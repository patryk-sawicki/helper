<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Hides a column that the always-blocked floor does NOT cover, so the per-instance behaviour of
 * $hidden can be tested without the floor answering first.
 */
class UserHidingNote extends User
{
    protected $hidden = ['private_note'];
}
