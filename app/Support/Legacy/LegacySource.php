<?php

namespace App\Support\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * Where legacy rows come from — a .sql dump on disk, or the old database itself.
 *
 * Both paths must produce byte-identical results, or a migration verified against
 * a dump could behave differently when re-run against the live database. That is
 * why the database reader casts every value to string|null: the SQL parser can
 * only ever yield strings, while a PDO driver hands back real ints, floats and
 * nulls, and the importer's own parsing ("-2.00" vs -2.0, "" vs null) would
 * otherwise diverge. Normalising here keeps the difference at the boundary.
 *
 * The database path is STRICTLY READ-ONLY — only SELECT is ever issued.
 */
class LegacySource
{
    public const FROM_FILES = 'files';

    public const FROM_DATABASE = 'database';

    /**
     * @param  string  $connection  A configured connection name (see config/database.php → legacy)
     */
    public function __construct(
        private string $mode,
        private ?string $dir = null,
        private string $connection = 'legacy',
    ) {}

    public static function files(string $dir): self
    {
        return new self(self::FROM_FILES, rtrim($dir, '/\\'));
    }

    public static function database(string $connection = 'legacy'): self
    {
        return new self(self::FROM_DATABASE, null, $connection);
    }

    public function describe(): string
    {
        return $this->mode === self::FROM_FILES
            ? 'SQL dumps in ' . $this->dir
            : 'the live legacy database (' . (config("database.connections.{$this->connection}.database") ?: '?') . ')';
    }

    /**
     * Confirm the source is actually usable, so a misconfiguration fails before
     * anything is analysed rather than halfway through.
     *
     * @return string|null An error message, or null when the source is good.
     */
    public function check(): ?string
    {
        if ($this->mode === self::FROM_FILES) {
            foreach ([self::EYE_TABLE, self::ESTIMATE_TABLE] as $table) {
                $path = $this->pathFor($table);
                if (! is_readable($path)) {
                    return "Cannot read {$path}";
                }
            }

            return null;
        }

        if (! config("database.connections.{$this->connection}.database")) {
            return "The \"{$this->connection}\" connection has no database configured. "
                . 'Set LEGACY_DB_DATABASE (and host/username/password) in .env.';
        }

        try {
            DB::connection($this->connection)->getPdo();
        } catch (\Throwable $e) {
            return "Cannot connect to the legacy database: {$e->getMessage()}";
        }

        foreach ([self::EYE_TABLE, self::ESTIMATE_TABLE] as $table) {
            if (! DB::connection($this->connection)->getSchemaBuilder()->hasTable($table)) {
                return "The legacy database has no \"{$table}\" table.";
            }
        }

        return null;
    }

    private const EYE_TABLE = 'eyerecourd';

    private const ESTIMATE_TABLE = 'estimatebook';

    /** @return list<array<string,string|null>> */
    public function eyeRecords(): array
    {
        return $this->read(self::EYE_TABLE);
    }

    /** @return list<array<string,string|null>> */
    public function estimates(): array
    {
        return $this->read(self::ESTIMATE_TABLE);
    }

    /** @return list<array<string,string|null>> */
    private function read(string $table): array
    {
        if ($this->mode === self::FROM_FILES) {
            return SqlDumpParser::parseTable($this->pathFor($table), $table);
        }

        return DB::connection($this->connection)
            ->table($table)
            ->get()
            ->map(static fn ($row) => array_map(
                static fn ($value) => $value === null ? null : (string) $value,
                (array) $row,
            ))
            ->values()
            ->all();
    }

    /** phpMyAdmin names a per-table export `<database>_table_<table>.sql`. */
    private function pathFor(string $table): string
    {
        return $this->dir . '/u174003801_sahaj_optical_table_' . $table . '.sql';
    }
}
