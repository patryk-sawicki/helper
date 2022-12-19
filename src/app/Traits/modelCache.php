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
     * @param array $withRelations List of relations to load.
     * @param array $select
     * @return Collection
     */
    public static function getList(array $withRelations = [], array $select = []): Collection
    {
        $cacheName = self::$cacheName ?? strtolower(str_replace('\\', '_', self::class));

        return Cache::tags([$cacheName])
            ->remember($cacheName . '_' . implode('_', $withRelations) . '_' . implode('_', $select), config('app.cache_default_ttl', 86400),
                function() use ($withRelations, $select) {
                    return self::with($withRelations)
                        ->when(!empty($select), function($query) use ($select) {
                            $query->select($select);
                        })
                        ->get();
                });
    }
}
