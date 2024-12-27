<?php

namespace PatrykSawicki\Helper\app\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
    public static string $cacheName = 'files';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'mime_type',
        'file',
        'width',
        'height',
        'additional_properties',
        'protected_type',
        'protected_id',
        'model_type',
        'model_id',
        'relation_type',
    ];

    protected function casts(): array
    {
        return [
            'additional_properties' => AsArrayObject::class,
        ];
    }

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
        return $this->morphMany($this::class, 'model')->where('relation_type', '=', 'thumbnails');
    }

    public function source(): MorphOne
    {
        return $this->morphOne($this::class, 'model')->where('relation_type', '=', 'source');
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
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_srcset_' . $this->id,
                config('app.cache_default_ttl', 86400),
                function () {
                    $srcset = $this->url() . ' ' . $this->width . 'w';

                    foreach ($this->thumbnails as $thumbnail) {
                        $srcset .= ', ' . $thumbnail->url() . ' ' . $thumbnail->width . 'w';
                    }

                    return $srcset;
                }
            );
    }

    /**
     * Return image html code.
     *
     */
    public function img(
        ?int $width = null,
        ?int $height = null,
        ?string $class = null,
        ?string $alt = null,
        ?string $style = null,
        string $loading = 'lazy',
        string $fetchPriority = 'auto',
        ?string $title = null,
        bool $source = false // If true, use source file instead of this file
    ): string
    {
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_img_' . $this->id . '_' . implode('_', func_get_args()),
                config('app.cache_default_ttl', 86400),
                function () use ($width, $height, $class, $alt, $style, $loading, $fetchPriority, $title, $source) {
                    $srcset = '';
                    if ($source && $this->source) {
                        $url = $this->source->url();
                        $width = $this->source->width;
                        $height = $this->source->height;
                    } else {
                        $source = false;
                        $url = $this->url();
                        $srcset = ' srcset="' . $this->srcset() . '"';
                    }

                    if (is_null($width) && is_null($height)) {
                        $width = 1920;
                    }

                    $thumbnail = $this->thumbnail($width, $height);

                    if (!is_null($class)) {
                        $class = 'class="' . $class . '"';
                    }

                    $sizes = !is_null($width) ? 'sizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';

                    $alt ??= $this->additional_properties?->alt;
                    $title ??= $this->additional_properties?->title;

                    return '<img src="' . $url . '" ' . $srcset . ' ' . $sizes . ' ' . $class . ' alt="' . $alt . '" title="' . $title . '" style="' . $style . '" loading="' . $loading . '" width="' . $thumbnail->width . '" height="' . $thumbnail->height . '" fetchpriority="' . $fetchPriority . '">';
                }
            );
    }

    /**
     * Return image preload html code.
     *
     */
    public function imgPreload(
        ?int $width = null,
    ): string {
        if (is_null($width)) {
            $width = 1920;
        }

        $sizes = !is_null($width) ? 'imagesizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';

        return '<link rel="preload" as="image" href="' . $this->url() . '" imagesrcset="' . $this->srcset() . '" ' . $sizes . '>';
    }

    public function scopeMainFile(Builder $query, $fileClass): Builder
    {
        return $query->whereNull('model_type')->orWhere('model_type', '!=', $fileClass);
    }

    public static function findBySlug(string $slug): ?self
    {
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_findBySlug_' . $slug,
                config('app.cache_default_ttl', 86400),
                function () use ($slug) {
                    return self::where('slug', '=', $slug)->with('thumbnails')->first();
                }
            );
    }

    public function fullStoragePatch(): string
    {
        return storage_path('app' . $this->file);
    }
}
