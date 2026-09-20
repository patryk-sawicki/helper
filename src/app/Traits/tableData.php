<?php


namespace PatrykSawicki\Helper\app\Traits;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/*
 * Trait for getting data by api to data tables.
 * */

trait tableData
{
    /**
     * Memoised verdicts of isAllowedRelationPath(), keyed by model class, opt-in list and path.
     *
     * @var array<string, bool>
     */
    protected array $relationPathVerdicts = [];

    /**
     * Column paths already reported as rejected, so one request logs each of them once.
     *
     * @var array<string, true>
     */
    protected array $reportedRejections = [];

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
            // sortBy() reads the name off each model through data_get(), so the gate runs per
            // element rather than once for the first: a collection may hold more than one class.
            $sortValue = function ($item) use ($sortColumn) {
                if ($item instanceof Model && $this->isReadableColumnPath($item, $sortColumn)) {
                    return data_get($item, $sortColumn);
                }

                if ($item instanceof Model) {
                    $this->reportRejectedColumn($item, $sortColumn);
                }

                return null;
            };

            $elements = ($sortDir == 'asc') ? $elements->sortBy($sortValue) : $elements->sortByDesc($sortValue);
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
        $elements->load($this->filterRelationPaths($elements->first(), $relations));

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

            // The segment comes from the request and resolving it as a relation calls the method
            // it names - see isAllowedRelationPath().
            if (!$this->isAllowedRelationPath($item, $model)) {
                $this->reportRejectedColumn($item, $colName);

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

        // The column name comes from the request - see isReadableColumnName().
        if (!$item instanceof Model) {
            return false;
        }

        if (!$this->isReadableColumnName($item, $colName)) {
            $this->reportRejectedColumn($item, $colName);

            return false;
        }

        $testValue = mb_strtolower($item->{$colName}, 'UTF-8');

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
            $this->applySortingToQuery($query, $sortColumn, $sortDir);
        }

        /*Search*/
        if (!is_null($search)) {
            $query->where(function (Builder $query) use ($request, $search) {
                foreach ($request->columns as $column) {
                    if ($column['searchable'] == '1') {
                        $colName = $column['name'];

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
        $elements->load($this->filterRelationPaths($query->getModel(), $relations));

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

        // whereHas() resolves the relation by calling $model->{$route}(), so an unchecked route
        // would invoke any no-argument method named in the request. Same gate as sorting.
        if (!$this->isAllowedRelationPath($query->getModel(), $route)) {
            return;
        }

        $query->whereHas($route, function ($query) use ($colName, $value) {
            $query->where($colName, 'like', '%' . $value . '%');
        });
    }

    /**
     * Apply sorting to the query, handling both simple columns and relation columns.
     *
     * @param Builder $query
     * @param string $sortColumn
     * @param string $sortDir
     * @return void
     */
    protected function applySortingToQuery(Builder $query, string $sortColumn, string $sortDir): void
    {
        // If the column doesn't contain a dot, it's a simple column - use standard sorting
        if (!str_contains($sortColumn, '.')) {
            ($sortDir == 'asc') ? $query->orderBy($sortColumn) : $query->orderByDesc($sortColumn);
            return;
        }

        // Column contains a dot - it's a relation
        $parts = explode('.', $sortColumn);
        $column = array_pop($parts);
        $relationName = implode('.', $parts);

        // Get the model instance from the query
        $model = $query->getModel();

        // Build the join for the relation and get the full column name
        $sortColumnWithTable = $this->joinRelationForSorting($query, $model, $relationName, $column);

        ($sortDir == 'asc') ? $query->orderBy($sortColumnWithTable) : $query->orderByDesc($sortColumnWithTable);
    }

    /**
     * Join a relation table for sorting purposes.
     *
     * @param Builder $query
     * @param Model $model
     * @param string $relationName
     * @param string $column
     * @return string The full column name to use in orderBy
     */
    protected function joinRelationForSorting(Builder $query, Model $model, string $relationName, string $column): string
    {
        // Handle only simple (non-nested) relations for now
        if (str_contains($relationName, '.')) {
            // For nested relations, fall back to simple order (may not work correctly)
            return $relationName . '.' . $column;
        }

        // The sort column comes straight from the request, so the method is only ever invoked
        // once it is proven to be an Eloquent relation. See isSortableRelation().
        if (!$this->isSortableRelation($model, $relationName)) {
            return $relationName . '.' . $column;
        }

        $relation = $model->{$relationName}();

        if (!$relation instanceof Relation) {
            return $relationName . '.' . $column;
        }

        $parentTable = $model->getTable();

        // Handle BelongsTo relation
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            $relatedTable = $relation->getRelated()->getTable();
            $foreignKey = $relation->getForeignKeyName();
            $ownerKey = $relation->getOwnerKeyName();

            // Add left join if not already joined
            if (!$this->hasJoin($query, $relatedTable)) {
                $query->leftJoin(
                    $relatedTable,
                    "{$parentTable}.{$foreignKey}",
                    '=',
                    "{$relatedTable}.{$ownerKey}"
                );
            }

            // Select main table to avoid ambiguity with id columns
            $query->select("{$parentTable}.*");

            return "{$relatedTable}.{$column}";
        }

        // Handle HasOne relation
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            $relatedTable = $relation->getRelated()->getTable();
            $foreignKey = $relation->getForeignKeyName();
            $localKey = $relation->getLocalKeyName();

            if (!$this->hasJoin($query, $relatedTable)) {
                $query->leftJoin(
                    $relatedTable,
                    "{$parentTable}.{$localKey}",
                    '=',
                    "{$relatedTable}.{$foreignKey}"
                );
            }

            $query->select("{$parentTable}.*");

            return "{$relatedTable}.{$column}";
        }

        // For other relation types (HasMany, BelongsToMany, etc.), fall back to simple column
        // These would require subqueries and more complex logic
        return $relationName . '.' . $column;
    }

    /**
     * Decide whether $name may be invoked on $model while resolving a sortable relation.
     *
     * The sort column is attacker-controlled: it arrives as columns[i][name] in the request.
     * Calling an arbitrary method whose name merely exists on the model turns sorting into a
     * "call any no-argument method" primitive (save(), push(), and anything a project trait adds).
     *
     * This gate is therefore an allow-list, never a deny-list of known-dangerous names: a method
     * is invoked only when its DECLARED return type proves it is an Eloquent relation, which is
     * knowable without running it. Relations declared without a return type are not sortable by
     * default; a model opts them in explicitly through a public $sortableRelations array.
     *
     * @param Model $model
     * @param string $name
     * @return bool
     */
    protected function isSortableRelation(Model $model, string $name): bool
    {
        if (!method_exists($model, $name)) {
            return false;
        }

        try {
            $method = new ReflectionMethod($model, $name);
        } catch (ReflectionException) {
            return false;
        }

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        // Explicit opt-in on the model, for relations declared without a return type.
        if (in_array($name, $this->declaredSortableRelations($model), true)) {
            return true;
        }

        $returnType = $method->getReturnType();

        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return false;
        }

        $returns = $returnType->getName();

        return $returns === Relation::class || is_subclass_of($returns, Relation::class);
    }

    /**
     * Read the model's $sortableRelations opt-in list whatever its visibility.
     *
     * Eloquent's __get() turns a read of a protected property into an attribute lookup that
     * yields null, so a protected or private list would silently behave as an empty one.
     *
     * @param Model $model
     * @return array
     */
    protected function declaredSortableRelations(Model $model): array
    {
        if (!property_exists($model, 'sortableRelations')) {
            return [];
        }

        try {
            $property = new ReflectionProperty($model, 'sortableRelations');
        } catch (ReflectionException) {
            return [];
        }

        if ($property->isStatic()) {
            return (array) $property->getValue();
        }

        return (array) $property->getValue($model);
    }

    /**
     * Report a column the allow-list refused, once per request and path.
     *
     * Rejection is otherwise invisible: the filter simply returns nothing and the sort stops
     * ordering, which looks exactly like missing data. Since this package is installed through a
     * caret constraint, the narrowing arrives with a routine update, and whoever gets the bug
     * report needs something to find.
     *
     * The column name comes from the table definition, not from what the operator typed - the
     * search phrase never goes in here, which is why 0.7.15 dropped its logger() calls.
     *
     * @param Model $item
     * @param string $path
     * @return void
     */
    protected function reportRejectedColumn(Model $item, string $path): void
    {
        $key = get_class($item) . '|' . $path;

        if (isset($this->reportedRejections[$key])) {
            return;
        }

        $this->reportedRejections[$key] = true;

        Log::warning('tableData: column rejected by the relation allow-list', [
            'model' => get_class($item),
            'column' => $path,
        ]);
    }

    /**
     * Validate a full column path - relation segments plus the value at the end.
     *
     * Reading a dotted path walks relations and then reads one name off the model it lands on,
     * so both halves need the gate: the segments as relations, the last one as data.
     *
     * @param Model $model
     * @param string $path
     * @return bool
     */
    protected function isReadableColumnPath(Model $model, string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // data_get() reads these as wildcards rather than names, so they identify no column and
        // would make the sort key a serialisation of the row instead of one of its values.
        if (preg_match('/[*{}\\\\]/', $path)) {
            return false;
        }

        $segments = explode('.', $path);
        $leaf = array_pop($segments);
        $current = $model;

        foreach ($segments as $segment) {
            if (!$this->isAllowedRelationPath($current, $segment)) {
                return false;
            }

            // An opt-in relation carries no declared return type, so re-check before getRelated().
            $relation = $current->{$segment}();

            if (!$relation instanceof Relation) {
                return false;
            }

            $current = $relation->getRelated();
        }

        return $this->isReadableColumnName($current, $leaf);
    }

    /**
     * Decide whether a single column name may be read off the model.
     *
     * getAttribute() reads the attribute array first, then hands unknown names to
     * getRelationValue(), which returns a loaded relation as-is and only CALLS a relation method
     * when isRelation() claims the name is one - isRelation() itself calls nothing, it is a
     * method_exists() check. So three cases resolve without reaching a relation: a real attribute
     * (a column may share its name with an ordinary method, and the attribute wins), a relation
     * that is already loaded, and a name that is no relation at all. Only the remaining case - a
     * relation that would have to be resolved - needs the allow-list that guards sorting, load()
     * and whereHas(); a column may legitimately name one, because the search branch serialises a
     * to-one relation with toJson().
     *
     * This does not promise that reading runs nothing: an accessor declared for the name still
     * runs, as it does anywhere else a model attribute is read. What it rules out is a name from
     * the request selecting an arbitrary method of the model.
     *
     * The order of checks inside getAttribute() is what makes this hold; verified against
     * laravel/framework 11.48, 12.56 and 13.32.
     *
     * @param Model $item
     * @param string $name
     * @return bool
     */
    protected function isReadableColumnName(Model $item, string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (array_key_exists($name, $item->getAttributes()) || $item->relationLoaded($name)) {
            return true;
        }

        return !$item->isRelation($name) || $this->isAllowedRelationPath($item, $name);
    }

    /**
     * Validate a dotted relation path, one segment at a time, against the same gate as sorting.
     *
     * Both eager loading and whereHas() resolve a relation by CALLING the method the request
     * names, so every segment has to be proven a relation before the path is handed to Eloquent.
     *
     * @param Model|null $model
     * @param string $path
     * @return bool
     */
    protected function isAllowedRelationPath(?Model $model, string $path): bool
    {
        if (is_null($model) || $path === '') {
            return false;
        }

        // Asked once per row of the collection, so the method reflection and the Relation objects
        // built below are memoised away; reading the opt-in list for the key is what remains. The
        // verdict depends on the class, that list and the path - never on the row's data - and the
        // list is in the key because $sortableRelations may be declared per instance. An instance
        // property rather than a static one: the path comes from the request, so these keys must
        // not outlive the request in a long-running worker.
        $cacheKey = get_class($model) . '|' . implode(',', $this->declaredSortableRelations($model)) . '|' . $path;

        if (array_key_exists($cacheKey, $this->relationPathVerdicts)) {
            return $this->relationPathVerdicts[$cacheKey];
        }

        $current = $model;

        foreach (explode('.', $path) as $segment) {
            if (!$this->isSortableRelation($current, $segment)) {
                return $this->relationPathVerdicts[$cacheKey] = false;
            }

            $relation = $current->{$segment}();

            if (!$relation instanceof Relation) {
                return $this->relationPathVerdicts[$cacheKey] = false;
            }

            $current = $relation->getRelated();
        }

        return $this->relationPathVerdicts[$cacheKey] = true;
    }

    /**
     * Keep only the relation paths that are safe to hand to Eloquent.
     *
     * @param Model|null $model
     * @param array $paths
     * @return array
     */
    protected function filterRelationPaths(?Model $model, array $paths): array
    {
        if (is_null($model)) {
            return [];
        }

        return array_values(array_filter(
            $paths,
            fn ($path) => is_string($path) && $this->isAllowedRelationPath($model, $path)
        ));
    }

    /**
     * Check if the query already has a join with the given table.
     *
     * @param Builder $query
     * @param string $table
     * @return bool
     */
    protected function hasJoin(Builder $query, string $table): bool
    {
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if ($join->table === $table) {
                return true;
            }
        }

        return false;
    }
}
