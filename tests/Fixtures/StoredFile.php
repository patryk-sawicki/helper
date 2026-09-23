<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A row written by addFile().
 *
 * The table is `files` because createSlug() checks slugs against that name directly.
 */
class StoredFile extends Model
{
    protected $table = 'files';

    public $timestamps = false;

    protected $guarded = [];
}
