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
        $columns = $this->columnsOf($index);

        $this->assertEquals('company_id', $columns[0] ?? null, "{$index} does not lead with company_id");
        $this->assertCount(2, $columns, "{$index} is not the two-column index expected");
    }

    /**
     * The point of the whole migration, asserted on the planner rather than on
     * the schema: a scan here is the regression.
     *
     * SQLite-specific, so it is skipped elsewhere - the index assertions above
     * are what hold on every driver.
     */
    #[DataProvider('hotQueries')]
    public function test_the_planner_uses_an_index_rather_than_scanning(string $sql, string $expectedIndex): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('EXPLAIN output is driver-specific; asserted on sqlite only.');
        }

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$sql))
            ->map(fn ($row) => (array) $row)
            ->flatMap(fn ($row) => array_values($row))
            ->implode(' ');

        $this->assertStringNotContainsString('SCAN', $plan, "the planner scans instead of seeking: {$plan}");
        $this->assertStringContainsString($expectedIndex, $plan, "expected {$expectedIndex}, got: {$plan}");
    }

    /** @return array<int, array<int, string>> */
    public static function hotQueries(): array
    {
        return [
            [
                "select count(*) from skips where company_id = 1 and date = '2026-10-08' and cancelled_at is null",
                'skips_company_id_date_index',
            ],
            [
                "select sum(quantity) from meal_adjustments where company_id = 1 and date = '2026-10-08'",
                'meal_adjustments_company_id_date_index',
            ],
            [
                "select * from users where company_id = 1 and role = 'company_admin'",
                'users_company_id_role_index',
            ],
            [
                "select * from users where company_id = 1 and login_code = 'ACME002'",
                'users_company_id_login_code_index',
            ],
        ];
    }

    /**
     * The migration must be reversible, or it cannot be rolled back on a
     * deployment that goes wrong.
     */
    public function test_the_migration_rolls_back_and_forward(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertFalse(Schema::hasIndex('skips', 'skips_company_id_date_index'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasIndex('skips', 'skips_company_id_date_index'));
    }

    /** @return array<int, string> */
    protected function columnsOf(string $index): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('index introspection here is written for sqlite.');
        }

        return collect(DB::select("PRAGMA index_info('{$index}')"))
            ->sortBy('seqno')
            ->pluck('name')
            ->all();
    }
}
