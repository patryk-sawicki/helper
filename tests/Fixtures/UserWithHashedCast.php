<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

class UserWithHashedCast extends User
{
    /**
     * Nothing is hidden here: the cast alone has to keep private_note out of the list.
     */
    protected $hidden = [];

    protected $casts = ['private_note' => 'hashed'];
}
