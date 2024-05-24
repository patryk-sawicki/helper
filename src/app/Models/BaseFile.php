<?php

namespace PatrykSawicki\Helper\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use PatrykSawicki\Helper\app\Traits\files;

/**
 * @mixin Builder
 * @property string file
 * @property Collection pageParameterImages
 */
abstract class BaseFile extends Model
{
    use files;
    use SoftDeletes;

    protected $table = 'files';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'mime_type',
        'file',
        'width',
        'height',
        'protected_type',
        'protected_id',
        'model_type',
        'model_id',
    ];

    protected array $protectedRelations = [
        'pageParameterImages',
    ];

    public function __toString(): string
    {
        return $this->url();
    }

    public function url(): string
    {
        return '/file/' . $this->id;
    }

    public function fullUrl(): string
    {
        return url($this->url());
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function protected(): MorphTo
    {
        return $this->morphTo();
    }

    public function thumbnails(): MorphMany
    {
        return $this->morphMany($this::class, 'model');
    }

    public function canBeDeleted(): bool
    {
        foreach ($this->protectedRelations as $relation) {
            if ($this->{$relation}->count() > 0) {
                return false;
            }
        }

        return true;
    }

    public function thumbnail($width, $height = null)
    {
        $thumbnails = $this->thumbnails;

        if (!is_null($width)) {
            $thumbnails = $thumbnails->where('width', '=', $width);
        }

        if (!is_null($height)) {
            $thumbnails = $thumbnails->where('height', '=', $height);
        }

        return $thumbnails->first() ?? $this;
    }

    public function icon($width, $height = null): string
    {
        if (explode('/', $this->mime_type)[0] === 'image') {
            return $this->thumbnail($width, $height);
        }

        return '/img/icons/' . $this->type . '.svg';
    }

    /**
     * Return html code for image with srcset.
     */
    public function srcset(): string
    {
        $srcset = $this->url() . ' ' . $this->width . 'w';

        foreach ($this->thumbnails as $thumbnail) {
            $srcset .= ', ' . $thumbnail->url() . ' ' . $thumbnail->width . 'w';
        }

        return $srcset;
    }

    /**
     * Return image html code.
     *
     * @param int|null $width
     * @param int|null $height
     * @param string|null $class
     * @param string|null $alt
     * @param string|null $style
     * @param string $loading
     * @return string
     */
    public function img(
        ?int $width = null,
        ?int $height = null,
        ?string $class = null,
        ?string $alt = null,
        ?string $style = null,
        string $loading = 'lazy',
        string $fetchPriority = 'auto'
    ): string {
        if (is_null($width) && is_null($height)) {
            $width = 1920;
        }

        $thumbnail = $this->thumbnail($width, $height);

        if (!is_null($class)) {
            $class = 'class="' . $class . '"';
        }

        $sizes = !is_null($width) ? 'sizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';

        return '<img src="' . $this->url() . '" srcset="' . $this->srcset(
            ) . '" ' . $sizes . ' ' . $class . ' alt="' . $alt . '" style="' . $style . '" loading="' . $loading . '" width="' . $thumbnail->width . '" height="' . $thumbnail->height . '" fetchpriority="' . $fetchPriority . '">';
    }

    public function scopeMainFile(Builder $query, $fileClass): Builder
    {
        return $query->whereNull('model_type')->orWhere('model_type', '!=', $fileClass);
    }
}
