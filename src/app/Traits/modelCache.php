<?php


namespace PatrykSawicki\Helper\app\Traits;


use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/*
 * Trait for using cache in models.
 * */
trait modelCache
{
    /**
     * Return list of cars using cache.
     *
     * @param array $loadRelations List of relations to load.
     * @return Collection
     */
    public static function getList(array $loadRelations): Collection
    {
        $cacheName = self::$cacheName ?? strtolower(str_replace('\\', '_', self::class));

        return Cache::tags([$cacheName])
            ->remember($cacheName . '_' . implode('_', $loadRelations), config('app.cache_default_ttl', 86400),
                function() use ($loadRelations) {
                    return self::with($loadRelations)->get();
                });
    }
}
