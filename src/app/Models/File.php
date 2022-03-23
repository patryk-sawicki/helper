<?php

namespace PatrykSawicki\Helper\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'mime_type',
        'file',
        'width',
        'height',
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

    public function thumbnails(): MorphMany
    {
        return $this->morphMany(File::class, 'model');
    }
}
