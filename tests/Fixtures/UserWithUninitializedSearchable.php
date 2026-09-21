<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * A typed property with no value: reading it through reflection throws Error, not
 * ReflectionException.
 */
class UserWithUninitializedSearchable extends User
{
    public array $searchableColumns;
}
