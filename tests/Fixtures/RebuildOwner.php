<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PatrykSawicki\Helper\app\Traits\uploads;

/**
 * An owner that uploads files the way a project model does, so rebuildFromSource() can run end to end.
 *
 * $fillable is deliberate. rebuildFromSource() calls addFile(externalRelation: false), which writes
 * `model_id`, the key of the morph, onto the owner. An owner has no such column; with a $fillable that
 * leaves it out, as a project's models usually have, mass assignment drops the key silently, while
 * with `$guarded = []` it would reach the query and fail (see Requirements in
 * docs/rebuildFromSource.md).
 */
class RebuildOwner extends Model
{
    use uploads;

    protected $table = 'helper_owners';

    public $timestamps = false;

    protected $fillable = ['name'];

    public function files(): MorphMany
    {
        return $this->morphMany(RebuildableFile::class, 'model');
    }
}
