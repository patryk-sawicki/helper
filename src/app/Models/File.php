<?php

namespace PatrykSawicki\Helper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin Builder
 */
class File extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable=[
        'name',
        'type',
        'file',
        'model_type',
        'model_id',
    ];

    public function __toString(): string
    {
        return '/file/'.$this->id;
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
