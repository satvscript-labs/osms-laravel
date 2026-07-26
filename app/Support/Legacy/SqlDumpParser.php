<?php

namespace App\Support\Legacy;

use RuntimeException;

/**
 * Minimal reader for a phpMyAdmin `INSERT` dump.
 *
 * Deliberately a hand-rolled scanner rather than a regex: the legacy data
 * contains apostrophes inside names (`D'SOUZA`), backslashes, and commas inside
 * quoted values, all of which quietly break a naive `explode`/regex split and
 * would silently corrupt a row rather than fail loudly.
 *
 * SQL was chosen over CSV for this migration precisely because every value here
 * is an explicit quoted string — a spreadsheet can't reinterpret `6/4` (a Snellen
 * visual-acuity fraction) as a date the way it does in CSV.
 */
class SqlDumpParser
{
    /**
     * Parse every row of `INSERT INTO <table>` in the given dump file.
     *
     * @return list<array<string,string|null>> rows keyed by column name
     */
    public static function parseTable(string $path, string $table): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("SQL dump not found: {$path}");
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read SQL dump: {$path}");
        }

        $rows = [];
        $offset = 0;
        $needle = 'INSERT INTO `' . $table . '`';

        while (($pos = strpos($sql, $needle, $offset)) !== false) {
            $columns = self::readColumnList($sql, $pos + strlen($needle), $valuesAt);
            $offset = self::readTuples($sql, $valuesAt, $columns, $rows);
        }

        if ($rows === []) {
            throw new RuntimeException("No INSERT rows for `{$table}` found in {$path}");
        }

        return $rows;
    }

    /**
     * Read the `(`col`, `col`, …)` list that follows the table name, and report
     * where the tuple list begins.
     *
     * @return list<string>
     */
    private static function readColumnList(string $sql, int $from, ?int &$valuesAt): array
    {
        $open = strpos($sql, '(', $from);
        $close = strpos($sql, ')', $open === false ? $from : $open);

        if ($open === false || $close === false) {
            throw new RuntimeException('Malformed INSERT: could not read the column list.');
        }

        $raw = substr($sql, $open + 1, $close - $open - 1);
        $columns = array_map(
            static fn (string $c): string => trim(trim($c), '`'),
            explode(',', $raw)
        );

        $valuesKeyword = stripos($sql, 'VALUES', $close);
        if ($valuesKeyword === false) {
            throw new RuntimeException('Malformed INSERT: missing VALUES keyword.');
        }
        $valuesAt = $valuesKeyword + strlen('VALUES');

        return $columns;
    }

    /**
     * Scan `(…),(…),…;` tuples, appending each to $rows. Returns the offset just
     * past the terminating semicolon so the caller can look for the next INSERT.
     *
     * @param  list<string>  $columns
     * @param  list<array<string,string|null>>  $rows
     */
    private static function readTuples(string $sql, int $from, array $columns, array &$rows): int
    {
        $len = strlen($sql);
        $i = $from;
        $expected = count($columns);

        while ($i < $len) {
            // Skip whitespace and the separators between tuples.
            while ($i < $len && (ctype_space($sql[$i]) || $sql[$i] === ',')) {
                $i++;
            }

            // A semicolon ends this INSERT statement.
            if ($i < $len && $sql[$i] === ';') {
                return $i + 1;
            }

            if ($i >= $len || $sql[$i] !== '(') {
                return $i; // Not a tuple — let the caller move on.
            }

            $i++; // step past '('
            $values = [];
            $current = '';
            $inString = false;
            $wasQuoted = false;

            while ($i < $len) {
                $ch = $sql[$i];

                if ($inString) {
                    if ($ch === '\\' && $i + 1 < $len) {
                        // Escaped character: keep the escapee verbatim (\' \" \\ \n …).
                        $current .= self::unescape($sql[$i + 1]);
                        $i += 2;

                        continue;
                    }
                    if ($ch === "'") {
                        // A doubled '' inside a string is a literal apostrophe.
                        if ($i + 1 < $len && $sql[$i + 1] === "'") {
                            $current .= "'";
                            $i += 2;

                            continue;
                        }
                        $inString = false;
                        $i++;

                        continue;
                    }
                    $current .= $ch;
                    $i++;

                    continue;
                }

                if ($ch === "'") {
                    // Whitespace between the comma and the opening quote is
                    // formatting, not data — drop it so values don't gain a
                    // leading space (which would break exact name matching).
                    if (trim($current) === '') {
                        $current = '';
                    }
                    $inString = true;
                    $wasQuoted = true;
                    $i++;

                    continue;
                }
                if ($ch === ',') {
                    $values[] = self::finalise($current, $wasQuoted);
                    $current = '';
                    $wasQuoted = false;
                    $i++;

                    continue;
                }
                if ($ch === ')') {
                    $values[] = self::finalise($current, $wasQuoted);
                    $i++;
                    break;
                }
                $current .= $ch;
                $i++;
            }

            // Only keep well-formed tuples; a mismatch means the dump is damaged,
            // and silently importing a shifted row would corrupt real records.
            if (count($values) === $expected) {
                $rows[] = array_combine($columns, $values);
            }
        }

        return $i;
    }

    /** Translate the escape sequences phpMyAdmin emits. */
    private static function unescape(string $ch): string
    {
        return match ($ch) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '0' => "\0",
            default => $ch,
        };
    }

    /**
     * A quoted value is returned verbatim (internal spacing is real data); an
     * unquoted token is trimmed, and a bare NULL becomes a real null. The
     * $wasQuoted flag matters: the string 'NULL' is data, bare NULL is absence.
     */
    private static function finalise(string $value, bool $wasQuoted): ?string
    {
        if ($wasQuoted) {
            return $value;
        }

        $trimmed = trim($value);

        return strcasecmp($trimmed, 'NULL') === 0 ? null : $trimmed;
    }
}
