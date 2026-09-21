<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * A typed opt-in list with no value: reading it through reflection throws Error.
 */
class OwnerWithUninitializedSortable extends Owner
{
    public array $sortableRelations;
}
