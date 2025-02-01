<?php


namespace PatrykSawicki\Helper\app\Traits;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use function PHPUnit\Framework\stringEndsWith;

/*
 * Trait for getting data by api to data tables.
 * */

trait tableData
{
    /**
     * Get searching relations.
     *
     * @param Request $request
     * @return array
     */
    public function getSearchingRelations(Request $request): array
    {
        if (is_null($request->columns)) {
            return [];
        }

        $result = array_column(array_filter($request->columns, function ($column) {
            return str_contains($column['name'], '.') && !is_null($column['search']['value']);
        }), 'name');

        foreach ($result as $key => $value) {
            $temp = explode('.', $value);
            $result[$key] = implode('.', array_slice($temp, 0, count($temp) - 1));
        }

        return array_unique($result);
    }

    /**
     * Get table relations.
     *
     * @param Request $request
     * @return array
     */
    public function getTableRelations(Request $request): array
    {
        if (is_null($request->columns)) {
            return [];
        }

        $result = array_column(array_filter($request->columns, function ($column) {
            return str_contains($column['name'], '.');
        }), 'name');

        foreach ($result as $key => $value) {
            $temp = explode('.', $value);
            $result[$key] = implode('.', array_slice($temp, 0, count($temp) - 1));
        }

        return array_unique($result);
    }

    public function getTableDataForObjects(Request $request, $elements, bool $sort = true): array
    {
        $start = $request->start ?? 0;
        $length = $request->length ?? 100;
        $sortDir = $request->order[0]['dir'] ?? 'asc';
        $sortColumn = (isset($request->order[0]['column']) ? $request->columns[$request->order[0]['column']]['name'] : 'id') ?? 'id';
        $draw = $request->draw ?? 1;
        $search = ($request->has('search') && !empty($request->search['value'])) ? trim(
            json_encode(mb_strtolower($request->search['value'], 'UTF-8')),
            '"'
        ) : null;
        $relations = $this->getTableRelations($request);

        $total = $elements->count();

        /*Sort*/
        if ($sort) {
            $elements = ($sortDir == 'asc') ? $elements->sortBy($sortColumn) : $elements->sortByDesc($sortColumn);
        }

        /*Search*/
        if (!is_null($search)) {
            $elements = $elements->filter(function ($item) use ($request, $search) {
                $test = mb_strtolower($item->__toString(), 'UTF-8');
                $value = trim(json_encode(mb_strtolower($request->search['value'] ?? '', 'UTF-8')), '"');
                $value2 = trim(mb_strtolower($request->search['value'] ?? '', 'UTF-8'), '"');
                $value3 = trim(json_encode(mb_strtoupper($request->search['value'] ?? '', 'UTF-8')), '"');
                return (str_contains(strip_tags($test), mb_strtolower($value, 'UTF-8')) ||
                    str_contains(strip_tags($test), mb_strtolower($value2, 'UTF-8')) ||
                    str_contains(strip_tags(strtolower($item->toJson())), $value) ||
                    str_contains(strip_tags($item->toJson()), $value3));
            });
        }

        /*Column Search*/
        if ($request->has('columns')) {
            foreach ($request->columns as $column) {
                $colName = $column['name'];

                if ($column['searchable'] && !empty($column['search']['value'])) {
                    $elements = $elements->filter(function ($item) use ($column, $colName) {
                        return $this->filterTableDataForObjects($item, $column, $colName);
                    });
                }
            }
        }

        $filtered = $elements->count();

        /*Start*/
        $elements = $elements->slice($start);

        /*Take*/
        $elements = $elements->take($length);

        /*Load relations*/
        $elements->load($relations);

        return [$elements, $draw, $total, $filtered];
    }

    protected function filterTableDataForObjects($item, $column, $colName): bool
    {
        if (is_null($item)) {
            return false;
        }

        if (str_contains($colName, '.')) {
            [$model, $column['name']] = explode('.', $colName, 2);

            if (!$item instanceof Model) {
                foreach ($item as $el) {
                    if ($this->filterTableDataForObjects($el, $column, $colName)) {
                        return true;
                    }
                }
                return false;
            }

            return $this->filterTableDataForObjects($item->{$model}, $column, $column['name']);
        }

        if (is_countable($item)) {
            foreach ($item as $el) {
                if ($this->filterTableDataForObjects($el, $column, $colName)) {
                    return true;
                }
            }

            return false;
        }

        $testValue = stringEndsWith('()')->evaluate($colName, '', true) ?
            mb_strtolower($item->{trim($colName, '()')}(), 'UTF-8') :
            mb_strtolower($item->{$colName}, 'UTF-8');

        $value = trim(json_encode(mb_strtolower($column['search']['value'] ?? '', 'UTF-8')), '"');
        $value2 = trim(mb_strtolower($column['search']['value'] ?? '', 'UTF-8'), '"');
        $value3 = trim(json_encode(mb_strtoupper($column['search']['value'] ?? '', 'UTF-8')), '"');

        return (str_contains(strip_tags($testValue), mb_strtolower($value, 'UTF-8')) ||
            str_contains(strip_tags($testValue), mb_strtolower($value2, 'UTF-8')) ||
            (is_object($item->{$colName}) && str_contains(
                    strip_tags(strtolower($item->{$colName}->toJson())),
                    $value
                )) ||
            (is_object($item->{$colName}) && str_contains(strip_tags($item->{$colName}->toJson()), $value3)));
    }

    public function getCachedTableData(
        Request $request,
        $class,
        bool $sort = true,
        array $scopes = [],
        string $cacheNameModifier = ''
    ): array {
        $cacheName = $class::$cacheName ?? strtolower(str_replace('\\', '_', $class));

        return Cache::tags([$cacheName])
            ->remember(
                $cacheName . '_' . $request->getContent() . '_' . $class . '_' . ($sort ? '1' : '0') . '_' . implode(
                    '_',
                    $scopes
                ) . '_' . $cacheNameModifier,
                config('app.cache_default_ttl', 86400),
                function () use ($request, $class, $sort, $scopes) {
                    return $this->getTableData($request, $class, $sort, $scopes);
                }
            );
    }

    public function getTableData(Request $request, $class, bool $sort = true, array $scopes = []): array
    {
        $start = $request->start ?? 0;
        $length = $request->length ?? 100;
        $sortDir = $request->order[0]['dir'] ?? 'asc';
        $sortColumn = (isset($request->order[0]['column']) ? $request->columns[$request->order[0]['column']]['name'] : 'id') ?? 'id';
        $draw = $request->draw ?? 1;
        $search = ($request->has('search') && !empty($request->search['value'])) ? trim(
            json_encode(mb_strtolower($request->search['value'], 'UTF-8')),
            '"'
        ) : null;
        $relations = $this->getTableRelations($request);

        $total = $class::count();

        $query = $class::query();

        /*Sort*/
        if ($sort) {
            ($sortDir == 'asc') ? $query->orderBy($sortColumn) : $query->orderByDesc($sortColumn);
        }

        /*Search*/
        if (!is_null($search)) {
            $query->where(function (Builder $query) use ($request, $search) {
                foreach ($request->columns as $column) {
                    if ($column['searchable'] == '1') {
                        $colName = $column['name'];
                        logger($colName);
                        logger($column);

                        $query->orWhere(function (Builder $query) use ($colName, $search) {
                            $this->filterQueryTableData($query, $colName, $search);
                        });
                    }
                }
            });
        }

        /*Column Search*/
        if ($request->has('columns')) {
            foreach ($request->columns as $column) {
                $colName = $column['name'];
                $value = trim($column['search']['value'] ?? '');

                if ($column['searchable'] == '1' && !empty($value)) {
                    $query->where(function (Builder $query) use ($colName, $value) {
                        $this->filterQueryTableData($query, $colName, $value);
                    });
                }
            }
        }

        /*Scopes*/
        foreach ($scopes as $key => $scope) {
            if (is_int($key)) {
                $query->{$scope}();
            } else {
                $query->{$key}($scope);
            }
        }

        $filtered = $query->count();

        /*Start*/
        $query->skip($start);

        /*Take*/
        $query->limit($length);

        /*Get*/
        $elements = $query->get();

        /*Load relations*/
        $elements->load($relations);

        return [$elements, $draw, $total, $filtered];
    }

    protected function filterQueryTableData(&$query, $colName, $value)
    {
        if (!str_contains($colName, '.')) {
            $query->where($colName, 'like', '%' . $value . '%');
            return;
        }

        $route = substr($colName, 0, strrpos($colName, '.'));
        $colName = substr($colName, strrpos($colName, '.') + 1);

        $query->whereHas($route, function ($query) use ($colName, $value) {
            $query->where($colName, 'like', '%' . $value . '%');
        });
    }
}
