<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

class UserWithSearchableColumns extends User
{
    /**
     * Replaces the derived list outright - including for columns that are not hidden.
     */
    public array $searchableColumns = ['name'];
}
