<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;

/**
 * Casts a column through an encrypting cast given as a CLASS STRING, which does not begin with
 * the word "encrypted".
 */
class UserWithEncryptedCast extends User
{
    protected $hidden = [];

    protected $casts = ['private_note' => AsEncryptedArrayObject::class];
}
