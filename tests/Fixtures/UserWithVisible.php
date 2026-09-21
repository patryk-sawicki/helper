<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Protects its output with a $visible allow-list instead of $hidden.
 */
class UserWithVisible extends User
{
    protected $hidden = [];

    protected $visible = ['id', 'name', 'email'];
}
