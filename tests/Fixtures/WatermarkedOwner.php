<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use PatrykSawicki\Helper\app\Traits\files;

/**
 * A model that stores its files through the files trait, so a test can run addFile() end to end.
 */
class WatermarkedOwner extends Model
{
    use files;

    protected $table = 'helper_owners';

    public $timestamps = false;

    protected $guarded = [];

    public function files(): MorphMany
    {
        return $this->morphMany(StoredFile::class, 'model');
    }

    public function source(): MorphOne
    {
        return $this->morphOne(StoredFile::class, 'model')->where('relation_type', '=', 'source');
    }
}
