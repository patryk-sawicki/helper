<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A second morph target for helper_files.model.
 *
 * It carries a method named like Owner's relation, but returning a string - so a path proven on
 * an Owner row is a "call this method" instruction on an OtherOwner row.
 */
class OtherOwner extends Model
{
    protected $table = 'helper_owners';

    public $timestamps = false;

    protected $guarded = [];

    public static bool $filesRan = false;

    public function files(): string
    {
        static::$filesRan = true;

        return 'not a relation';
    }
}
