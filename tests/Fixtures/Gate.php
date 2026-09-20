<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use PatrykSawicki\Helper\app\Traits\tableData;

/**
 * The trait's protected surface, reachable from a test.
 *
 * A class using the trait is what a controller is, so the tests drive it the same way rather than
 * through reflection - except for filterQueryTableData(), whose first argument is by reference.
 */
class Gate
{
    use tableData;

    public function call(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }

    public function filterQuery($query, string $column, string $value): void
    {
        $this->filterQueryTableData($query, $column, $value);
    }
}
