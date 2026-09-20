<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Carries a public, argument-less method named exactly like one of its own columns.
 */
class UserWithColumnMethod extends User
{
    public static bool $nameMethodRan = false;

    public function name(): string
    {
        static::$nameMethodRan = true;

        return 'called';
    }
}
