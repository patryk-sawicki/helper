<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ways ORDER BY took a name straight from the request.
 *
 * joinRelationForSorting() used to answer "$relationName.$column" whenever it could not build a
 * join. The grammar wraps those two halves as table and column, so the string was not a fallback:
 * it was the request choosing what the database sorts by.
 */
class SortingGateTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
    }

    private function sqlFor(string $column): string
    {
        $query = User::query();
        $this->gate->call('applySortingToQuery', $query, $column, 'asc');

        return $query->toSql();
    }

    #[Test]
    public function a_hidden_column_named_with_its_own_table_is_refused(): void
    {
        // The plain branch refused `password`, so the request wrote `helper_users.password` and
        // the dotted branch sorted real rows by the hash. The table name is not a secret.
        $sql = $this->sqlFor('helper_users.password');

        $this->assertStringNotContainsString('order by', $sql);
        $this->assertStringNotContainsString('password', $sql);
    }

    #[Test]
    public function a_dotted_name_that_is_no_relation_is_refused(): void
    {
        $sql = $this->sqlFor('dangerousMethod.password');

        $this->assertStringNotContainsString('order by', $sql);
        $this->assertFalse(Owner::$dangerousMethodRan);
    }

    #[Test]
    public function a_nested_relation_path_is_refused(): void
    {
        $query = Owner::query();
        $this->gate->call('applySortingToQuery', $query, 'files.model.name', 'asc');

        $this->assertStringNotContainsString('order by', $query->toSql());
    }

    #[Test]
    public function a_to_many_relation_builds_no_join_and_is_refused(): void
    {
        // The column clears the gate, but no join was built for it, so naming it would order by
        // a table this query does not have.
        $query = Owner::query();
        $this->gate->call('applySortingToQuery', $query, 'files.name', 'asc');

        $this->assertStringNotContainsString('order by', $query->toSql());
    }

}
