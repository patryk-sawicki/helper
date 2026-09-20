<?php

namespace PatrykSawicki\Helper\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PatrykSawicki\Helper\Tests\Fixtures\File;
use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * getTableData() driven the way DataTables drives it.
 *
 * The request decides the column name AND the searchable flag, so the column the SQL ends up
 * asking about is entirely the client's choice unless the gate intervenes.
 */
class TableDataQueryTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();

        User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'aaa-hash']);
        User::create(['name' => 'Bartek', 'email' => 'bartek@example.com', 'password' => 'zzz-hash']);
    }

    private function request(string $column, string $value, ?string $sortColumn = null): Request
    {
        return Request::create('/data', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['name' => $sortColumn ?? $column, 'searchable' => '1', 'search' => ['value' => $value]],
            ],
        ]);
    }

    #[Test]
    public function a_hidden_column_never_reaches_the_sql(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        [$elements, , $total, $filtered] = $this->gate->getTableData($this->request('password', 'aaa'), User::class);

        $this->assertSame(2, $total);
        // A refused filter NARROWS. Dropping it would hand back the whole table as though it were
        // the result of filtering - recordsFiltered equal to recordsTotal, nothing visibly wrong.
        $this->assertSame(0, $filtered);
        $this->assertCount(0, $elements);

        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('password', $sql);
        }
    }

    #[Test]
    public function an_ordinary_column_still_filters(): void
    {
        [$elements, , $total, $filtered] = $this->gate->getTableData($this->request('name', 'Anna'), User::class);

        $this->assertSame(2, $total);
        $this->assertSame(1, $filtered);
        $this->assertSame('Anna', $elements->first()->name);
    }

    #[Test]
    public function sorting_by_a_hidden_column_leaves_the_query_unordered(): void
    {
        $query = User::query();
        $this->gate->call('applySortingToQuery', $query, 'password', 'asc');

        $this->assertStringNotContainsString('order by', $query->toSql());

        $query = User::query();
        $this->gate->call('applySortingToQuery', $query, 'name', 'asc');

        $this->assertStringContainsString('order by', $query->toSql());
    }

    #[Test]
    public function a_refused_column_leaves_a_trace_in_the_log(): void
    {
        // A gate that goes quiet is indistinguishable from missing data, so the refusal is logged.
        $records = [];
        Log::listen(function ($message) use (&$records) {
            $records[] = $message->message . ' ' . json_encode($message->context);
        });

        $query = User::query();
        $this->gate->filterQuery($query, 'password', 'aaa');

        $this->assertNotEmpty($records);
        $this->assertStringContainsString('column rejected', $records[0]);
        $this->assertStringContainsString('password', $records[0]);
    }

    #[Test]
    public function a_nonexistent_relation_is_refused(): void
    {
        // Refused by the RELATION gate - it never reaches the leaf, which is why this test alone
        // said nothing about the column on the other side.
        $query = User::query();
        $this->gate->filterQuery($query, 'nonexistentRelation.name', 'x');

        $this->assertStringNotContainsString('exists', $query->toSql());
    }

    #[Test]
    public function a_real_relation_with_a_leaf_that_is_no_column_is_refused(): void
    {
        // Here the relation clears the gate and the leaf is the only thing left to judge.
        Owner::create(['name' => 'Owner']);

        $query = Owner::query();
        $this->gate->filterQuery($query, 'files.not_a_column', 'x');

        $this->assertStringNotContainsString('exists', $query->toSql());
    }

    #[Test]
    public function a_real_relation_with_a_real_leaf_still_filters(): void
    {
        $owner = Owner::create(['name' => 'Owner']);
        File::create(['name' => 'match.txt', 'model_type' => Owner::class, 'model_id' => $owner->id]);
        Owner::create(['name' => 'No files']);

        $query = Owner::query();
        $this->gate->filterQuery($query, 'files.name', 'match');

        $this->assertStringContainsString('exists', $query->toSql());
        $this->assertSame(1, $query->count());
    }

    #[Test]
    public function a_refused_relation_filter_narrows_as_well(): void
    {
        Owner::create(['name' => 'Owner']);

        $query = Owner::query();
        $this->gate->filterQuery($query, 'files.not_a_column', 'x');

        $this->assertStringNotContainsString('exists', $query->toSql());
        $this->assertSame(0, $query->count());
    }

    #[Test]
    public function an_allowed_filter_is_unaffected_by_the_refusal_path(): void
    {
        [, , $total, $filtered] = $this->gate->getTableData($this->request('name', 'Anna'), User::class);

        $this->assertSame(2, $total);
        $this->assertSame(1, $filtered);
    }
}
