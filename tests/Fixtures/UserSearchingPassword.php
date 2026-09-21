<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Asks for the password column explicitly. The floor still refuses it.
 */
class UserSearchingPassword extends User
{
    public array $searchableColumns = ['name', 'password'];
}
