<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The indexes behind the queries that run on every page load.
 *
 * CalculateExpectedMeals runs for today on every dashboard, daily and vendor
 * view, plus seven more times for the forecast strip. Before these, EXPLAIN
 * showed a full table scan of meal_adjustments and of users, and a scan of
 * every skip a company had ever had.
 */
class HotPathIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A pre-computed bcrypt hash. Hashing three thousand passwords for a test
     * about query plans is pure waste.
     */
    protected const CHEAP_HASH = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQR';

    /** @return array<int, array<int, string>> */
    public static function expectedIndexes(): array
    {
        return [
            ['skips', 'skips_company_id_date_index'],
            ['meal_adjustments', 'meal_adjustments_company_id_date_index'],
            ['users', 'users_company_id_role_index'],
            ['users', 'users_company_id_login_code_index'],
        ];
    }

    #[DataProvider('expectedIndexes')]
    public function test_the_index_exists(string $table, string $index): void
    {
        $this->assertTrue(
            Schema::hasIndex($table, $index),
            "{$table} is missing {$index}",
        );
    }

    /**
     * The order matters: every query is scoped to one company first, so
     * (company_id, date) is usable and (date, company_id) would not be.
     */
    #[DataProvider('expectedIndexes')]
    public function test_the_index_leads_with_company_id(string $table, string $index): void
    {
        $columns = $this->columnsOf($table, $index);

        $this->assertEquals('company_id', $columns[0] ?? null, "{$index} does not lead with company_id");
        $this->assertCount(2, $columns, "{$index} is not the two-column index expected");
    }

    /**
     * The point of the whole migration, asserted on the planner rather than on
     * the schema: a scan here is the regression.
     *
     * Both drivers, with their own EXPLAIN. This was SQLite-only, which meant
     * the planner was never checked on the driver that is actually deployed.
     */
    #[DataProvider('hotQueries')]
    public function test_the_planner_uses_an_index_rather_than_scanning(string $sql, string $expectedIndex): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertPostgresSeeks($sql, $expectedIndex);

            return;
        }

        // SQLite reaches for an index on an empty table, so no data is needed
        // and the literal company id is irrelevant to the plan.
        $sql = str_replace('{company}', '1', $sql);

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$sql))
            ->map(fn ($row) => (array) $row)
            ->flatMap(fn ($row) => array_values($row))
            ->implode(' ');

        $this->assertStringNotContainsString('SCAN', $plan, "the planner scans instead of seeking: {$plan}");
        $this->assertStringContainsString($expectedIndex, $plan, "expected {$expectedIndex}, got: {$plan}");
    }

    /**
     * PostgreSQL chooses a sequential scan on a small table however good the
     * index is, and correctly so - reading a handful of pages beats an index
     * lookup. Asserting on an empty test database would therefore assert
     * nothing.
     *
     * So this seeds enough rows for the choice to be real and runs ANALYZE, then
     * asks the planner. If it still prefers a scan at that size, seqscan is
     * disabled and the plan re-read: that no longer proves the planner would
     * choose the index, only that the index is usable for the predicate - which
     * is the part a wrong column order would break. The distinction is asserted
     * explicitly rather than hidden.
     */
    protected function assertPostgresSeeks(string $sql, string $expectedIndex): void
    {
        // The literal matters here: PostgreSQL estimates selectivity from it,
        // and an id that matches no row estimates one row and picks an index
        // whatever the table looks like. Sequences are not rolled back with the
        // test transaction, so the seeded id is not predictable and has to be
        // substituted rather than hardcoded.
        $sql = str_replace('{company}', (string) $this->seedEnoughRowsForThePlannerToCare(), $sql);

        $plan = $this->postgresPlan($sql);

        if (str_contains($plan, $expectedIndex)) {
            $this->assertStringNotContainsString(
                'Seq Scan',
                $plan,
                "the planner scans instead of seeking: {$plan}",
            );

            return;
        }

        // Fall back to proving usability rather than preference.
        DB::statement('SET enable_seqscan = off');

        try {
            $forced = $this->postgresPlan($sql);
        } finally {
            DB::statement('SET enable_seqscan = on');
        }

        $this->assertStringContainsString(
            $expectedIndex,
            $forced,
            "{$expectedIndex} is not usable for this query even with seqscan disabled, ".
            "so the index does not match the predicate.\nnatural plan: {$plan}\nforced plan: {$forced}",
        );
    }

    protected function postgresPlan(string $sql): string
    {
        return collect(DB::select('EXPLAIN '.$sql))
            ->map(fn ($row) => (array) $row)
            ->flatMap(fn ($row) => array_values($row))
            ->implode(' ');
    }

    /**
     * One company, 200 employees, and a few thousand rows in each hot table -
     * enough that a sequential scan is no longer the obvious choice.
     *
     * Seeded once per test and only on PostgreSQL: SQLite's planner reaches for
     * an index on an empty table, so the SQLite assertions need none of this and
     * should not pay for it.
     */
    protected function seedEnoughRowsForThePlannerToCare(): int
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Index Rehearsal Ltd', 'code' => 'IDX001',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminId = DB::table('users')->insertGetId([
            'name' => 'Index Admin', 'email' => 'index-admin@mealbells.test',
            'password' => self::CHEAP_HASH, 'role' => 'company_admin',
            'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $start = $now->copy()->subDays(30);
        $perEmployee = 25;

        $this->insertInChunks('employees', 200, fn (int $i) => [
            'company_id' => $companyId,
            'employee_code' => 'IDX'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'name' => "Person {$i}", 'status' => 'active', 'is_meal_eligible' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $employeeIds = DB::table('employees')->where('company_id', $companyId)->pluck('id')->all();

        // unique(employee_id, date) is enforced, so each employee gets its own
        // run of dates rather than drawing from a shared pool.
        $this->insertInChunks('skips', count($employeeIds) * $perEmployee, fn (int $i) => [
            'company_id' => $companyId,
            'employee_id' => $employeeIds[intdiv($i - 1, $perEmployee)],
            'date' => $start->copy()->addDays(($i - 1) % $perEmployee)->toDateString(),
            'source' => 'self',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->insertInChunks('meal_adjustments', 4000, fn (int $i) => [
            'company_id' => $companyId,
            'date' => $start->copy()->addDays($i % 40)->toDateString(),
            'quantity' => 1, 'type' => 'guest', 'created_by' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->insertInChunks('users', 3000, fn (int $i) => [
            'name' => "Index User {$i}",
            'email' => "index-user-{$i}@mealbells.test",
            'password' => self::CHEAP_HASH,
            'role' => 'employee',
            'company_id' => $companyId,
            'login_code' => 'IDXU'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Without statistics the planner is guessing, and a guess about a table
        // it has never looked at is a sequential scan.
        foreach (['skips', 'meal_adjustments', 'users'] as $table) {
            DB::statement("ANALYZE {$table}");
        }

        return $companyId;
    }

    /**
     * Rows are generated and flushed in batches, never accumulated.
     *
     * Building the whole set as one PHP array exhausted the 128 MB limit as soon
     * as this ran as part of the full suite rather than on its own.
     *
     * @param  callable(int): array<string, mixed>  $row
     */
    protected function insertInChunks(string $table, int $count, callable $row): void
    {
        $chunk = [];

        for ($i = 1; $i <= $count; $i++) {
            $chunk[] = $row($i);

            if (count($chunk) === 500) {
                DB::table($table)->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @return array<int, array<int, string>> */
    public static function hotQueries(): array
    {
        return [
            [
                "select count(*) from skips where company_id = {company} and date = '2026-10-08' and cancelled_at is null",
                'skips_company_id_date_index',
            ],
            [
                "select sum(quantity) from meal_adjustments where company_id = {company} and date = '2026-10-08'",
                'meal_adjustments_company_id_date_index',
            ],
            [
                "select * from users where company_id = {company} and role = 'company_admin'",
                'users_company_id_role_index',
            ],
            [
                "select * from users where company_id = {company} and login_code = 'ACME002'",
                'users_company_id_login_code_index',
            ],
        ];
    }

    /**
     * The migration must be reversible, or it cannot be rolled back on a
     * deployment that goes wrong.
     *
     * The migration object is loaded and its down()/up() called directly rather
     * than using `migrate:rollback --step 1`, which rolls back whatever happens
     * to be last - so the test does not break every time a later migration is
     * added, as it did once already.
     */
    public function test_the_migration_is_reversible(): void
    {
        $file = collect(glob(database_path('migrations/*_add_hot_path_indexes.php')))->first();
        $this->assertNotNull($file, 'the index migration was not found');

        $migration = require $file;

        $migration->down();

        foreach (self::expectedIndexes() as [$table, $index]) {
            $this->assertFalse(Schema::hasIndex($table, $index), "{$index} survived down()");
        }

        $migration->up();

        foreach (self::expectedIndexes() as [$table, $index]) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$index} did not come back");
        }
    }

    /**
     * Through the schema builder, which reports the same shape on both drivers.
     *
     * This used to read PRAGMA index_info and skip everywhere else, so the
     * column order - the thing that decides whether the index is usable at all -
     * was only ever checked on the development driver.
     *
     * @return array<int, string>
     */
    protected function columnsOf(string $table, string $index): array
    {
        $found = collect(Schema::getIndexes($table))->firstWhere('name', $index);

        $this->assertNotNull($found, "{$table} has no index named {$index}");

        return array_values($found['columns']);
    }
}
