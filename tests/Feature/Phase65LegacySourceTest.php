<?php

namespace Tests\Feature;

use App\Support\Legacy\LegacySource;
use App\Support\Legacy\SahajLegacyImporter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reading the old system directly must be indistinguishable from reading a dump
 * of it.
 *
 * This matters more than it looks. A migration is verified against dump files,
 * then run for real — and if `--from-db` parsed values even slightly differently
 * (a real NULL vs the string "NULL", -2.0 vs "-2.00", an int 0 vs ""), the run
 * would not be the thing that was signed off on.
 */
class Phase65LegacySourceTest extends TestCase
{
    private string $dir;

    /** @var list<array<string,mixed>> */
    private array $eyeRows = [
        ['id' => 1, 'name' => "PRIYA D'SOUZA", 'contectno' => '9824459668', 'date' => '2024-01-10',
            'lspl' => '-2.00', 'lcly' => '-0.50', 'laxis' => '90', 'lvs' => '6/9', 'leftadd' => '1.00', 'leftspl' => '-1.00',
            'rspl' => '-1.75', 'rcly' => null, 'raxis' => null, 'rvs' => '6/6', 'rightadd' => '1.00', 'rightspl' => '-0.75',
            'checkedby' => 'OLD REPORT'],
        ['id' => 2, 'name' => 'SUNITA SHAH', 'contectno' => '9824459668', 'date' => '2025-06-01',
            'lspl' => '0.00', 'lcly' => '', 'laxis' => '', 'lvs' => '6/6', 'leftadd' => '', 'leftspl' => '',
            'rspl' => '0.00', 'rcly' => '', 'raxis' => '', 'rvs' => '6/6', 'rightadd' => '', 'rightspl' => '',
            'checkedby' => 'Dr Mehta'],
        ['id' => 3, 'name' => 'RAHUL PATEL', 'contectno' => '0000000000', 'date' => '2024-03-05',
            'lspl' => '-1.50', 'lcly' => null, 'laxis' => null, 'lvs' => null, 'leftadd' => null, 'leftspl' => null,
            'rspl' => '-1.50', 'rcly' => null, 'raxis' => null, 'rvs' => null, 'rightadd' => null, 'rightspl' => null,
            'checkedby' => null],
    ];

    /** @var list<array<string,mixed>> */
    private array $estimateRows = [
        ['order_no' => 101, 'first_name' => 'SUNITA SHAH', 'contact' => '9824459668', 'date' => '2025-06-01', 'total' => 900],
        ['order_no' => 102, 'first_name' => 'CASH', 'contact' => '0000000000', 'date' => '2025-06-02', 'total' => 500],
        ['order_no' => 103, 'first_name' => 'AMIT VERMA', 'contact' => '9825011111', 'date' => '2025-02-02', 'total' => 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/legacysrc_' . uniqid();
        mkdir($this->dir);

        $this->writeDumps();
        $this->buildLegacyDatabase();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Render the same rows as a phpMyAdmin-style INSERT dump. */
    private function writeDumps(): void
    {
        foreach ([['eyerecourd', $this->eyeRows], ['estimatebook', $this->estimateRows]] as [$table, $rows]) {
            $columns = array_keys($rows[0]);
            $tuples = [];

            foreach ($rows as $row) {
                $values = array_map(static function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }

                    return "'" . str_replace("'", "''", (string) $v) . "'";
                }, $row);
                $tuples[] = '(' . implode(', ', $values) . ')';
            }

            file_put_contents(
                $this->dir . '/u174003801_sahaj_optical_table_' . $table . '.sql',
                "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES\n"
                . implode(",\n", $tuples) . ";\n",
            );
        }
    }

    /** Stand up a throwaway "old system" the DB reader can point at. */
    private function buildLegacyDatabase(): void
    {
        Config::set('database.connections.legacy', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $schema = Schema::connection('legacy');

        $schema->create('eyerecourd', function ($table) {
            $table->integer('id');
            foreach (['name', 'contectno', 'date', 'lspl', 'lcly', 'laxis', 'lvs', 'leftadd', 'leftspl',
                'rspl', 'rcly', 'raxis', 'rvs', 'rightadd', 'rightspl', 'checkedby'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $schema->create('estimatebook', function ($table) {
            $table->integer('order_no');
            $table->string('first_name')->nullable();
            $table->string('contact')->nullable();
            $table->string('date')->nullable();
            $table->decimal('total', 12, 2)->nullable();
        });

        DB::connection('legacy')->table('eyerecourd')->insert($this->eyeRows);
        DB::connection('legacy')->table('estimatebook')->insert($this->estimateRows);
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,mixed>} */
    private function analyse(LegacySource $source): array
    {
        $importer = (new SahajLegacyImporter($source->eyeRecords(), $source->estimates()))->analyse();

        return [$importer->profiles, $importer->stats];
    }

    public function test_both_sources_produce_identical_profiles(): void
    {
        [$fromFiles, $fileStats] = $this->analyse(LegacySource::files($this->dir));
        [$fromDb, $dbStats] = $this->analyse(LegacySource::database());

        $this->assertSame($fileStats, $dbStats, 'summary figures must match exactly');
        $this->assertEquals($fromFiles, $fromDb, 'every resolved profile must match exactly');
    }

    /**
     * The specific values most likely to diverge: a real NULL vs the string
     * "NULL", an empty string vs null, and a numeric column arriving typed.
     */
    public function test_null_empty_and_numeric_values_survive_both_paths(): void
    {
        [$fromFiles] = $this->analyse(LegacySource::files($this->dir));
        [$fromDb] = $this->analyse(LegacySource::database());

        $pick = static fn (array $profiles, string $name) => collect($profiles)->firstWhere('name', $name);

        foreach (['PRIYA D\'SOUZA', 'SUNITA SHAH', 'RAHUL PATEL'] as $name) {
            $this->assertEquals(
                $pick($fromFiles, $name)['eye_records'] ?? null,
                $pick($fromDb, $name)['eye_records'] ?? null,
                "prescriptions for {$name} must be identical from either source",
            );
        }
    }

    /** An apostrophe is the classic dump-parsing failure; it must round-trip. */
    public function test_an_apostrophe_in_a_name_survives_both_paths(): void
    {
        [$fromFiles] = $this->analyse(LegacySource::files($this->dir));
        [$fromDb] = $this->analyse(LegacySource::database());

        $this->assertNotNull(collect($fromFiles)->firstWhere('name', "PRIYA D'SOUZA"));
        $this->assertNotNull(collect($fromDb)->firstWhere('name', "PRIYA D'SOUZA"));
    }

    public function test_it_reports_a_missing_file_rather_than_throwing(): void
    {
        $this->assertStringContainsString('Cannot read', LegacySource::files($this->dir . '/nope')->check());
    }

    public function test_it_reports_an_unconfigured_connection(): void
    {
        Config::set('database.connections.legacy.database', null);

        $this->assertStringContainsString('LEGACY_DB_DATABASE', LegacySource::database()->check());
    }

    public function test_a_configured_source_reports_no_problem(): void
    {
        $this->assertNull(LegacySource::files($this->dir)->check());
        $this->assertNull(LegacySource::database()->check());
    }

    // ------------------------------------------------------- read-only guard

    /**
     * Hostinger's shared plans block CREATE USER/GRANT and allow only one user
     * per database, so the legacy connection cannot be a read-only account —
     * it reuses credentials with full privileges on the old system. The
     * application therefore enforces read-only itself, and that guard is the
     * only thing standing between a coding mistake and a live customer's
     * irreplaceable data.
     */
    private function guard(): void
    {
        // Mirrors AppServiceProvider::enforceLegacyReadOnly(), which cannot run
        // at boot here because the connection is configured inside setUp.
        DB::connection('legacy')->beforeExecuting(function (string $query): void {
            $sql = ltrim(preg_replace('#^(\s|/\*.*?\*/|--[^\n]*\n)+#s', '', $query) ?? $query);
            if (! preg_match('/^(select|show|describe|explain)\b/i', $sql)) {
                throw new \RuntimeException('The legacy connection is read-only: only SELECT is permitted.');
            }
        });
    }

    public function test_reading_is_still_allowed(): void
    {
        $this->guard();

        $this->assertCount(3, DB::connection('legacy')->table('eyerecourd')->get());
    }

    public function test_an_update_against_the_old_system_is_refused(): void
    {
        $this->guard();

        try {
            DB::connection('legacy')->table('eyerecourd')->where('id', 1)->update(['name' => 'WRECKED']);
            $this->fail('a write to the legacy connection must be refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        $this->assertSame(
            "PRIYA D'SOUZA",
            DB::connection('legacy')->table('eyerecourd')->where('id', 1)->value('name'),
            'the row must be untouched',
        );
    }

    public function test_a_delete_against_the_old_system_is_refused(): void
    {
        $this->guard();

        try {
            DB::connection('legacy')->table('eyerecourd')->delete();
            $this->fail('a delete must be refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        $this->assertSame(3, DB::connection('legacy')->table('eyerecourd')->count());
    }

    /** A leading comment must not disguise a write as harmless. */
    public function test_a_comment_prefixed_write_is_still_refused(): void
    {
        $this->guard();

        $this->expectException(\RuntimeException::class);
        DB::connection('legacy')->statement('/* harmless */ DROP TABLE eyerecourd');
    }
}
