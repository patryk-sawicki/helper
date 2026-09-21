<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Declares neither $hidden, nor $visible, nor a cast - the shape the derived list cannot protect
 * on its own, and the shape real consumer models turned out to have.
 */
class UserDeclaringNothing extends User
{
    protected $hidden = [];

    protected $casts = [];
}
