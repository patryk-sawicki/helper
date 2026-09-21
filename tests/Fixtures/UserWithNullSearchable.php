<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Declares the property but makes no choice - that is the derived default, not "allow nothing".
 */
class UserWithNullSearchable extends User
{
    public ?array $searchableColumns = null;
}
