<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class File extends Model
{
    protected $table = 'helper_files';

    public $timestamps = false;

    protected $guarded = [];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
