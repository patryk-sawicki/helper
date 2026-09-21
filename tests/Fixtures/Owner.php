<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Owner extends Model
{
    protected $table = 'helper_owners';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Set by dangerousMethod() so a test can tell whether the body actually ran.
     */
    public static bool $dangerousMethodRan = false;

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'model');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A public, argument-less, NON-relation method that File does not have.
     *
     * This is the shape the morph gate exists for: Eloquent's isRelation() is a method_exists()
     * check, so reading this name off a row calls it before finding out it returns no relation.
     */
    public function dangerousMethod(): string
    {
        static::$dangerousMethodRan = true;

        return 'ran';
    }
}
