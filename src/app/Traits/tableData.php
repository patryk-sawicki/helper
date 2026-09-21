<?php


namespace PatrykSawicki\Helper\app\Traits;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

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
     * Memoised searchable column lists, keyed by model class, connection, table and the
     * blocked/visible lists - see blockedColumns().
     *
     * Kept per instance rather than static for the same reason as the verdicts above: nothing
     * derived from a request outlives it in a long-running worker. On the shape of the key, see
     * blockedColumns().
     *
     * @var array<string, array<int, string>>
     */
    protected array $searchableColumnCache = [];

    /**
     * Memoised column listings, keyed by model class and table.
     *
     * Unlike the searchable list, this one depends on nothing the row carries.
     *
     * @var array<string, array<int, string>>
     */
    protected array $tableColumnCache = [];

    /**
     * Column paths already reported as rejected, so one request logs each of them once.
     *
     * @var array<string, true>
     */
    protected array $reportedRejections = [];

    /**
     * How many refusals this request has already written.
     *
     * Separate from the map above, which stops growing once the limit is reached.
     *
     * @var int
     */
    protected int $rejectionsReported = 0;

    /**
     * How many refused columns one request may write to the log, and how long a name may be.
     *
     * The name comes from the request, and so does the NUMBER of names, so both are amplifiers:
     * measured, 1000 refused columns wrote 1000 records and a single 8000-character name wrote
     * an 8 kB line. The trace has to stay useful without being a way to fill a disk.
     */
    protected const MAX_REJECTION_REPORTS = 20;

    /**
     * Names this gate refuses whatever the model says.
     *
     * The derived list follows the model's own $hidden, $visible and casts, which means a model
     * that declares none of them exposes every column of its table - and models like that exist
     * in the wild, with a password column among the rest. This floor is not a deny-list standing
     * in for the allow-list: it only ever REMOVES from what the allow-list already permits, so a
     * name outside it is no more allowed than before. A subclass may redefine either constant -
     * they are read through static:: - which is the way to search a column these names catch.
     */
    // `remember_token` is also caught by the `_token` suffix; it is spelled out because the
    // contract should be readable without composing the two lists in your head.
    protected const ALWAYS_BLOCKED_COLUMNS = [
        'password', 'password_hash', 'remember_token', 'salt', 'secret',
        'api_key', 'app_key', 'auth_key', 'hmac_key', 'signature_key',
        'recovery_codes', 'two_factor_recovery_codes',
    ];

    /**
     * Matched at the END of a name, and deliberately NOT '_hash' or '_key'.
     *
     * Those two read as secrets in `password_hash` and `api_key`, and as identifiers everywhere
     * else - a survey of the consumer migrations on hand found `short_hash`, `pdf_hash`,
     * `contract_hash`, `idempotency_key`, `checkout_key` and `feature_key`, all of them columns
     * a list is meant to be searched by. Blocking the suffix would break working screens to
     * protect names already covered above by their full spelling.
     */
    protected const ALWAYS_BLOCKED_SUFFIXES = ['_token', '_secret', '_password'];

    /**
     * Matched at the START of a name.
     */
    protected const ALWAYS_BLOCKED_PREFIXES = ['secret_'];

    protected const MAX_REJECTION_NAME_LENGTH = 200;

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
                    $this->reportRejectedColumn($item, $sortColumn, str_contains($sortColumn, '.') ? 'path' : 'column');
                }

                return null;
            };

            $elements = ($sortDir == 'asc') ? $elements->sortBy($sortValue) : $elements->sortByDesc($sortValue);
        }

        /*Search*/
        if (!is_null($search)) {
            $elements = $elements->filter(function ($item) use ($request, $search) {
                // The global search matches a substring of the SERIALISED row, which is a gate of
                // its own that never had one: toJson() of a model with no $hidden carries the
                // password hash, and a substring match over it reads that hash one character per
                // request. See searchableRepresentation().
                if (!$item instanceof Model) {
                    return false;
                }

                $haystack = $this->searchableRepresentation($item);
                $test = mb_strtolower($haystack, 'UTF-8');
                $value = trim(json_encode(mb_strtolower($request->search['value'] ?? '', 'UTF-8')), '"');
                $value2 = trim(mb_strtolower($request->search['value'] ?? '', 'UTF-8'), '"');
                $value3 = trim(json_encode(mb_strtoupper($request->search['value'] ?? '', 'UTF-8')), '"');
                return (str_contains(strip_tags($test), mb_strtolower($value, 'UTF-8')) ||
                    str_contains(strip_tags($test), mb_strtolower($value2, 'UTF-8')) ||
                    str_contains(strip_tags(strtolower($haystack)), $value) ||
                    str_contains(strip_tags($haystack), $value3));
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
        // Proven against EVERY row, not just the first. load() applies one set of paths to the
        // whole collection, while a collection may hold more than one class and more than one
        // morph target - and the request decides which row comes first, through the sort, the
        // search and the page. See filterRelationPathsForEveryRow().
        $elements->load($this->filterRelationPathsForEveryRow($elements, $relations));

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
                $this->reportRejectedColumn($item, $colName, 'relation');

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
            $this->reportRejectedColumn($item, $colName, str_contains($colName, '.') ? 'path' : 'column');

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
        // $scopes is applied by calling the named method on the builder, and nothing here proves
        // that name is a scope - it is the caller's job to make sure it comes from code. Feeding
        // it anything from the Request hands over "call any method of the builder", which is the
        // primitive the rest of this trait exists to take away.
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
        // Proven against every row, exactly as in getTableDataForObjects(): the rows are already
        // limited to the page, and a prototype would refuse every morph path outright, pushing
        // the consumer into a lazy load per row.
        $elements->load($this->filterRelationPathsForEveryRow($elements, $relations));

        return [$elements, $draw, $total, $filtered];
    }

    protected function filterQueryTableData(&$query, $colName, $value)
    {
        $model = $query->getModel();

        if (!str_contains($colName, '.')) {
            // The name is the identifier of the LIKE below, so it decides which column the row
            // count answers about. See searchableColumns().
            if (!$this->isSearchableColumn($model, $colName)) {
                $this->reportRejectedColumn($model, $colName, 'column');
                $this->matchNoRows($query);

                return;
            }

            $query->where($colName, 'like', '%' . $value . '%');
            return;
        }

        $route = substr($colName, 0, strrpos($colName, '.'));
        $leaf = substr($colName, strrpos($colName, '.') + 1);

        // whereHas() resolves the relation by calling $model->{$route}(), so an unchecked route
        // would invoke any no-argument method named in the request. Same gate as sorting.
        if (!$this->isAllowedRelationPath($model, $route)) {
            $this->reportRejectedColumn($model, $colName, 'relation');
            $this->matchNoRows($query);

            return;
        }

        $related = $this->relatedModelForPath($model, $route);

        // Checked before whereHas() rather than inside it: a closure that adds no condition
        // leaves "has any related row", which would widen the result set instead of narrowing it.
        if (is_null($related) || !$this->isSearchableColumn($related, $leaf)) {
            $this->reportRejectedColumn($related ?? $model, $colName, 'column');
            $this->matchNoRows($query);

            return;
        }

        $query->whereHas($route, function ($query) use ($leaf, $value) {
            $query->where($leaf, 'like', '%' . $value . '%');
        });
    }

    /**
     * The text of a row that the global search is allowed to match against.
     *
     * The search compares a substring of the whole serialised row, so whatever ends up in that
     * string is searchable whether or not any column gate ever saw it. toArray() already honours
     * each model's own $hidden and $visible, which leaves exactly the population the floor exists
     * for: a model declaring none of them serialises its password hash, and a substring match
     * over that hash is an oracle reading it one character per request. Measured before this:
     * searching `bcrypt$X` matched, `bcrypt$XY` matched, `bcrypt$QQ` did not.
     *
     * Eloquent's own __toString() only repeats toJson(), so it is folded in only when the model
     * declares one of its own - that is a representation the model chose.
     *
     * @param Model $item
     * @return string
     */
    protected function searchableRepresentation(Model $item): string
    {
        $parts = [json_encode($this->stripAlwaysBlocked($item->toArray())) ?: ''];

        try {
            $declaring = (new ReflectionMethod($item, '__toString'))->getDeclaringClass()->getName();

            if ($declaring !== Model::class) {
                $parts[] = (string) $item;
            }
        } catch (Throwable) {
            // No __toString() at all; the serialised attributes are the whole representation.
        }

        return implode(' ', $parts);
    }

    /**
     * Drop always-blocked keys from a serialised row, at every level.
     *
     * Relations are serialised along with the row, and a relation carries secrets just as well as
     * the row does. Each model's own $hidden is already applied by toArray(); this is the floor.
     *
     * @param array $data
     * @return array
     */
    protected function stripAlwaysBlocked(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isAlwaysBlockedColumn($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripAlwaysBlocked($value) : $value;
        }

        return $clean;
    }

    /**
     * Make the query match nothing, which is how a refused filter narrows.
     *
     * Returning without adding a condition leaves the surrounding where() group empty, and an
     * empty group is dropped - so the filter the client asked for simply vanishes and the list
     * comes back UNFILTERED. On the only path where the client is asking for fewer rows, that is
     * the wrong direction: the table then shows every row as though it were the result, with
     * recordsFiltered equal to recordsTotal and nothing to see in the UI. The collection path has
     * always narrowed on a refusal - filterTableDataForObjects() drops the row - so this is also
     * what makes the two entry points answer the same way.
     *
     * Inside the OR group of the global search this contributes nothing, which is correct there:
     * one refused column must not decide for the others.
     *
     * @param Builder $query
     * @return void
     */
    protected function matchNoRows($query): void
    {
        $query->whereRaw('1 = 0');
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
            // ORDER BY on a column the client picked is a weaker oracle than LIKE, but an oracle
            // all the same: the order of the rows answers about the column it names.
            if (!$this->isSearchableColumn($query->getModel(), $sortColumn)) {
                $this->reportRejectedColumn($query->getModel(), $sortColumn, 'column');

                return;
            }

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

        // null means the gate refused the column on the other side of the relation: leave the
        // query unordered rather than ordering by something the request chose.
        if (is_null($sortColumnWithTable)) {
            return;
        }

        ($sortDir == 'asc') ? $query->orderBy($sortColumnWithTable) : $query->orderByDesc($sortColumnWithTable);
    }

    /**
     * Join a relation table for sorting purposes.
     *
     * Every branch that cannot PROVE the column it is about to name returns null, and the caller
     * then leaves the query unordered. The fallbacks used to return "$relationName.$column" for
     * the request to sort by, which was not a fallback at all: the grammar wraps the two halves as
     * table and column, so `<table>.password` ordered real rows by the hash without touching a
     * relation, and a name that is no relation ordered by a table that does not exist (a 500 the
     * request chose). Neither had a legitimate use.
     *
     * @param Builder $query
     * @param Model $model
     * @param string $relationName
     * @param string $column
     * @return string|null The full column name to use in orderBy, or null when the gate refused it
     */
    protected function joinRelationForSorting(Builder $query, Model $model, string $relationName, string $column): ?string
    {
        // Nested relations would need a subquery to sort by; until that exists, refuse rather than
        // hand the request's own string to orderBy().
        if (str_contains($relationName, '.')) {
            $this->reportRejectedColumn($model, $relationName . '.' . $column, 'nested-sort');

            return null;
        }

        // The sort column comes straight from the request, so the method is only ever invoked
        // once it is proven to be an Eloquent relation. See isSortableRelation().
        if (!$this->isSortableRelation($model, $relationName)) {
            $this->reportRejectedColumn($model, $relationName . '.' . $column, 'relation');

            return null;
        }

        $relation = $model->{$relationName}();

        if (!$relation instanceof Relation) {
            $this->reportRejectedColumn($model, $relationName . '.' . $column, 'relation');

            return null;
        }

        // The column on the far side of the relation is named by the request too, and it ends up
        // in ORDER BY against the joined table. See searchableColumns().
        $related = $this->relatedModelForPath($model, $relationName);

        if (is_null($related) || !$this->isSearchableColumn($related, $column)) {
            $this->reportRejectedColumn($related ?? $model, $relationName . '.' . $column, 'column');

            return null;
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

        // Other relation types (HasMany, BelongsToMany, …) would need a subquery to sort by. The
        // column itself cleared the gate above, but no join was built for it, so naming it here
        // would order by a table this query does not have.
        $this->reportRejectedColumn($model, $relationName . '.' . $column, 'to-many-sort');

        return null;
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

            // Same reason as in declaredSearchableColumns(): a typed property with no value
            // throws Error, not ReflectionException, and an uncaught Error here is a 500 on
            // every table request for such a model.
            if (!$property->isStatic() && !$property->isInitialized($model)) {
                return [];
            }

            $value = $property->isStatic() ? $property->getValue() : $property->getValue($model);
        } catch (Throwable) {
            return [];
        }

        return (array) $value;
    }

    /**
     * Read the model's $searchableColumns opt-in list, or null when it declares none.
     *
     * Same reflection as declaredSortableRelations(): Eloquent's __get() turns a read of a
     * protected property into an attribute lookup that yields null, so a protected or private
     * list would silently behave as an empty one. Null and [] mean different things here - no
     * list at all falls back to the derived default, an empty one allows nothing.
     *
     * @param Model $model
     * @return array<int, string>|null
     */
    protected function declaredSearchableColumns(Model $model): ?array
    {
        if (!property_exists($model, 'searchableColumns')) {
            return null;
        }

        try {
            $property = new ReflectionProperty($model, 'searchableColumns');

            // A typed property with no value throws Error, not ReflectionException, and Error is
            // not caught by the clause below - that is a 500 on every table request for such a
            // model. isInitialized() answers without reading.
            if (!$property->isStatic() && !$property->isInitialized($model)) {
                return null;
            }

            $value = $property->isStatic() ? $property->getValue() : $property->getValue($model);
        } catch (Throwable) {
            return null;
        }

        // A declared-but-null list means the model did not make a choice, which is the derived
        // default - not "allow nothing". An empty ARRAY does mean allow nothing, and stays.
        if (is_null($value)) {
            return null;
        }

        return array_values(array_filter((array) $value, 'is_string'));
    }

    /**
     * Whether this name is refused regardless of what the model declares.
     *
     * See ALWAYS_BLOCKED_COLUMNS for why the floor exists and why it is not a deny-list.
     *
     * @param string $name
     * @return bool
     */
    protected function isAlwaysBlockedColumn(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, array_map('strtolower', static::ALWAYS_BLOCKED_COLUMNS), true)) {
            return true;
        }

        foreach (static::ALWAYS_BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($name, strtolower($suffix))) {
                return true;
            }
        }

        foreach (static::ALWAYS_BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($name, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Columns the model itself declares as not for output.
     *
     * @param Model $model
     * @return array<int, string>
     */
    protected function blockedColumns(Model $model): array
    {
        // Deliberately NOT memoised per class: $hidden, $visible and $casts belong to the
        // INSTANCE, and makeVisible() on one row would otherwise decide for every row of that
        // class - the gate would open on exactly the column it is here to close.
        //
        // Measured, 20 000 calls (1000 rows x 20 columns) on PHP 8.5: 9.4 ms for a plain model,
        // 17.2 ms once a $visible list is present, and it grows with the number of attributes
        // and casts - a wide model has been measured near 39 ms. That is real but small next to
        // what the collection path already does per row, and it buys a verdict that cannot be
        // inherited from another row. The expensive part is the schema read, and that one IS
        // memoised - by a key carrying this list, in searchableColumns().
        $blocked = $model->getHidden();

        // A model can protect its output the other way round, with a $visible allow-list and an
        // empty $hidden. Everything outside that list is then just as much "not for output", and
        // without this branch the whole gate is a no-op for such models.
        $visible = $model->getVisible();

        if ($visible !== []) {
            $blocked = array_merge(
                $blocked,
                array_diff(array_keys($model->getAttributes()), $visible)
            );
        }

        foreach ($model->getCasts() as $column => $cast) {
            if (!is_string($cast)) {
                continue;
            }

            // Matching is on the whole cast, lowercased: the built-in ones are the literals
            // 'hashed' and 'encrypted…', but Eloquent also takes class strings, and
            // AsEncryptedCollection::class does not START with 'encrypted'.
            $normalised = strtolower($cast);

            if ($normalised === 'hashed' || str_contains($normalised, 'encrypted')) {
                $blocked[] = $column;
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * Columns of $model a request may name while filtering or sorting a query.
     *
     * The name arrives as columns[i][name] and becomes the identifier of a LIKE and of an ORDER
     * BY. The identifier is wrapped, so this is not about injection: it is that a client free to
     * name any column of the table turns the row count into an oracle and reads a secret out of
     * it one character at a time, password and remember_token included. The searchable flag next
     * to the name is no gate either - it comes from the same request.
     *
     * So this is an allow-list, derived from the model rather than from the request: the columns
     * the table really has, less the ones the model declares as not for output. A model that
     * needs a different set - an accessor, a narrower list - declares a public
     * $searchableColumns, which replaces the derived one outright, the way $sortableRelations
     * opts relations in.
     *
     * A schema that cannot be read proves nothing about what is safe, so it yields an empty list.
     *
     * @param Model $model
     * @return array<int, string>
     */
    protected function searchableColumns(Model $model): array
    {
        $declared = $this->declaredSearchableColumns($model);

        if (!is_null($declared)) {
            // Memoised like the derived branch: the collection path asks once per row and per
            // column, and this branch otherwise re-ran the reflection read plus two passes over
            // the list every single time.
            $declaredKey = implode("\0", ['declared', get_class($model), implode("\0", $declared)]);

            if (array_key_exists($declaredKey, $this->searchableColumnCache)) {
                return $this->searchableColumnCache[$declaredKey];
            }

            // The floor applies over an explicit list too - otherwise "always blocked" would mean
            // "unless someone writes it down", which is not a floor.
            return $this->searchableColumnCache[$declaredKey] = array_values(array_filter(
                $declared,
                fn (string $column) => !$this->isAlwaysBlockedColumn($column)
            ));
        }

        $blocked = $this->blockedColumns($model);
        $visible = $model->getVisible();

        // The key carries the blocked list and the visible list, not just the class - see
        // blockedColumns() for why those belong to the row. The separator is a NUL byte because
        // a column name may legally contain a comma.
        $cacheKey = implode("\0", [
            get_class($model),
            (string) $model->getConnectionName(),
            $model->getTable(),
            implode("\0", $blocked),
            implode("\0", $visible),
        ]);

        if (array_key_exists($cacheKey, $this->searchableColumnCache)) {
            return $this->searchableColumnCache[$cacheKey];
        }

        $columns = $this->tableColumns($model);

        // A $visible allow-list is narrowed against the SCHEMA, not against the attributes the
        // instance happens to carry. Every caller on the query path hands in a prototype -
        // Builder::getModel(), Relation::getRelated() - which carries none at all, so a
        // complement taken from the attributes is empty and the allow-list does nothing.
        if ($visible !== []) {
            $columns = array_intersect($columns, $visible);
        }

        $columns = array_filter(
            array_diff($columns, $blocked),
            fn (string $column) => !$this->isAlwaysBlockedColumn($column)
        );

        return $this->searchableColumnCache[$cacheKey] = array_values($columns);
    }

    /**
     * Columns the model's table really has.
     *
     * Memoised by class and table: the schema is a property of those, not of the row, and a table
     * request asks for the same model on every column it carries. A schema that cannot be read
     * yields an empty list, which refuses rather than guesses.
     *
     * @param Model $model
     * @return array<int, string>
     */
    protected function tableColumns(Model $model): array
    {
        // The connection is part of the key: a model repointed with setConnection() - one tenant
        // per database, same table name - would otherwise inherit the first tenant's answer.
        $cacheKey = implode("\0", [get_class($model), (string) $model->getConnectionName(), $model->getTable()]);

        if (array_key_exists($cacheKey, $this->tableColumnCache)) {
            return $this->tableColumnCache[$cacheKey];
        }

        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (Throwable) {
            // Refuse, but do NOT remember the refusal: a momentary database error would then
            // silence the gate for the rest of the request.
            return [];
        }

        return $this->tableColumnCache[$cacheKey] = $columns;
    }

    /**
     * Decide whether a query may filter or sort on $name.
     *
     * @param Model $model
     * @param string $name
     * @return bool
     */
    protected function isSearchableColumn(Model $model, string $name): bool
    {
        return $name !== '' && in_array($name, $this->searchableColumns($model), true);
    }

    /**
     * Whether a MorphTo built from $current resolves to a target this gate cannot know.
     *
     * Eloquent picks the target from the type column of the row the relation was built from, so
     * getRelated() is the real target while that column is filled - and the parent model, i.e. a
     * class that never holds the rest of the path, once it is empty. That is the state of every
     * instance getRelated() itself produces. The three places that walk a path share this one
     * predicate; what differs between them is what they do with a TRAILING morph, and that stays
     * at the call site, where it is readable.
     *
     * @param Model $current
     * @param Relation $relation
     * @return bool
     */
    protected function isMorphTargetUnknown(Model $current, Relation $relation): bool
    {
        // Read raw. getAttribute() falls through to getRelationValue() for a name it does not
        // find among the attributes, and that CALLS a method carrying that name - so asking it
        // for the type column would make the gate invoke something it has not proven, and a
        // model with a method named like its own type column would 500 on every table request.
        return $relation instanceof MorphTo
            && blank($current->getAttributes()[$relation->getMorphType()] ?? null);
    }

    /**
     * Walk a relation path that isAllowedRelationPath() has already accepted and return the model
     * it lands on, or null when that model cannot be known.
     *
     * A trailing MorphTo is the null case: the gate lets one through because nothing is validated
     * past it, but a column filter validates exactly that - the column on the other side - and on
     * an instance without a type there is no other side to check it against.
     *
     * @param Model $model
     * @param string $path
     * @return Model|null
     */
    protected function relatedModelForPath(Model $model, string $path): ?Model
    {
        $current = $model;

        foreach (explode('.', $path) as $segment) {
            // Both of today's call sites validate the path first, so this guard is redundant for
            // them - and that is exactly why it belongs here. This is published protected surface
            // that CALLS the method it is handed a name for; leaving the invariant in a comment
            // means the next call site inherits a "call any no-argument method" primitive from
            // a method that looks like a lookup.
            if (!$this->isSortableRelation($current, $segment)) {
                return null;
            }

            $relation = $current->{$segment}();

            if (!$relation instanceof Relation) {
                return null;
            }

            if ($this->isMorphTargetUnknown($current, $relation)) {
                return null;
            }

            $current = $relation->getRelated();
        }

        return $current;
    }

    /**
     * Report a column the allow-list refused, once per request and path.
     *
     * Rejection is otherwise invisible: the filter simply returns nothing and the sort stops
     * ordering, which looks exactly like missing data. Since this package is installed through a
     * caret constraint, the narrowing arrives with a routine update, and whoever gets the bug
     * report needs something to find.
     *
     * The search phrase never goes in here, which is why 0.7.15 dropped its logger() calls. The
     * COLUMN NAME does, and it comes from the request like everything else on this path - an
     * earlier version of this docblock claimed otherwise and that claim was the reason nothing
     * sanitised it. Laravel's LineFormatter runs with allowInlineLineBreaks, which un-escapes a
     * \n inside the JSON context back into a real newline, so a name carrying one wrote a second
     * physical line into the log that reads like a record of its own. Control characters are
     * stripped, the name is truncated, and the number of records one request may produce is
     * capped - 1000 refused columns in a single request were 1000 records before.
     *
     * $reason names WHICH gate refused, because after 0.7.17 most refusals no longer come from
     * the relation allow-list and a trace pointing at the wrong gate sends the reader the wrong
     * way. One of: relation, column, path, nested-sort, to-many-sort - where `path` means a
     * dotted column whose refusal could have come from either half, so the trace says so rather
     * than guessing. The default is `unspecified` on purpose: a call site that forgets the
     * argument must not silently claim a gate.
     *
     * @param Model $item
     * @param string $path
     * @param string $reason
     * @return void
     */
    protected function reportRejectedColumn(Model $item, string $path, string $reason = 'unspecified'): void
    {
        $key = get_class($item) . '|' . $path;

        if (isset($this->reportedRejections[$key])) {
            return;
        }

        // Past the limit nothing more is remembered either: the map holds full, untrimmed names
        // from the request, and it is the request that decides how many there are.
        if ($this->rejectionsReported >= static::MAX_REJECTION_REPORTS) {
            if ($this->rejectionsReported === static::MAX_REJECTION_REPORTS) {
                $this->rejectionsReported++;

                Log::warning('tableData: further rejected columns not reported for this request', [
                    'model' => get_class($item),
                    'reported' => static::MAX_REJECTION_REPORTS,
                ]);
            }

            return;
        }

        $this->reportedRejections[$key] = true;
        $this->rejectionsReported++;

        Log::warning('tableData: column rejected', [
            'model' => get_class($item),
            'column' => $this->sanitiseForLog($path),
            'reason' => $reason,
        ]);
    }

    /**
     * Make a request-controlled string safe to put in a log record.
     *
     * @param string $value
     * @return string
     */
    protected function sanitiseForLog(string $value): string
    {
        // U+0085, U+2028 and U+2029 are line breaks to a good part of the log tooling, so they
        // belong here next to the ASCII controls.
        $pattern = '/[\x{0000}-\x{001F}\x{007F}\x{0085}\x{2028}\x{2029}]/u';

        // A malformed byte makes the /u variant return null, and a null turned into '' would
        // erase the column name from the record - the trace would vanish on exactly the input
        // that most deserves one. Scrub first, and fall back to the ASCII-only pattern.
        $clean = preg_replace($pattern, '', $value);

        if (is_null($clean)) {
            $clean = preg_replace($pattern, '', mb_scrub($value));
        }

        if (is_null($clean)) {
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        }

        return mb_strlen($clean) > static::MAX_REJECTION_NAME_LENGTH
            ? mb_substr($clean, 0, static::MAX_REJECTION_NAME_LENGTH) . '…'
            : $clean;
    }

    /**
     * Validate a full column path - relation segments plus the value at the end.
     *
     * Reading a dotted path walks relations and then reads one name off the model it lands on,
     * so both halves need the gate: the segments as relations, the last one as data.
     *
     * $model is a row here, not a fresh instance, which is what makes a MorphTo segment passable
     * at all: the row carries the type of its own morph target, so the leaf can be proven against
     * the class it will really be read off.
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

            // Here the segment is always followed by the leaf, so a morph is never trailing and
            // an unknown target always poisons the class the leaf gets checked against. Nothing
            // is queried to decide this: the type is already on the row.
            if ($this->isMorphTargetUnknown($current, $relation)) {
                return false;
            }

            $current = $relation->getRelated();
        }

        return $this->isReadableColumnName($current, $leaf);
    }

    /**
     * Decide whether a single column name may be read off the model.
     *
     * Six checks, in this order: the name is not empty; it carries no dot (every caller splits
     * the path first); it is a real column of the TABLE, in which case the allow-list decides;
     * and then the three cases getAttribute() itself distinguishes. The third check stands
     * before those three deliberately - a real column is judged by the schema, not by whether
     * this instance happens to carry it, because the leaf of a dotted column is validated
     * against a prototype that carries nothing.
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
     * A real column of the table carries a second gate, which is not about invoking anything: a
     * column the model declares as not for output answers about itself through the number of rows
     * that survive the filter. See searchableColumns() for why the request cannot be trusted to
     * have narrowed that down already. That gate still defers to the relation rules when the row
     * does not carry the attribute, because then reading the name does reach a method.
     *
     * The order of checks inside getAttribute() is what makes this hold; verified against
     * laravel/framework 11.56, 12.69 and 13.32.
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

        // This method judges ONE name, and every caller splits the path before reaching it. A
        // name still carrying a dot is therefore a caller that forgot to, and answering it as if
        // it were a column would mean answering about a column nobody checked: `<table>.password`
        // is neither an attribute nor a relation, so the fall-through at the bottom would let it
        // pass. Refusing is the only answer this method can give correctly.
        if (str_contains($name, '.')) {
            return false;
        }

        // Before anything else, so the promise holds on every branch below. Put inside the
        // attribute case it covered one of four, and a name that is no column of the table - an
        // accessor called api_secret, say - walked past it into the fall-through.
        if ($this->isAlwaysBlockedColumn($name)) {
            return false;
        }

        // A real column of the table is judged by the allow-list and by nothing else - in
        // particular NOT by whether this instance happens to carry it. isReadableColumnPath()
        // validates the leaf of a dotted column against a PROTOTYPE of the related model, which
        // carries no attributes at all, so gating on attribute presence made "not loaded here"
        // read as "not a column" and let `<relation>.password` through to sort real rows by the
        // hash - the same oracle, reached by the dotted form.
        if (in_array($name, $this->tableColumns($item), true)) {
            // The allow-list decides, but it is not the only question: an instance that does not
            // carry this attribute - a partial select(), a prototype - sends the read down
            // getRelationValue(), which CALLS a method carrying the name. So a column that is
            // also the name of a method still has to clear the relation gate. For an ordinary
            // secret like password isRelation() is false, so this costs that case nothing.
            return $this->isSearchableColumn($item, $name)
                && (array_key_exists($name, $item->getAttributes())
                    || !$item->isRelation($name)
                    || $this->isAllowedRelationPath($item, $name));
        }

        // Past that the name is no column of the table: an attribute the query produced (a
        // counted relation, an accessor materialised into the attributes), a loaded relation, a
        // relation that would have to be resolved, or nothing at all.
        if (array_key_exists($name, $item->getAttributes())) {
            $declared = $this->declaredSearchableColumns($item);

            return is_null($declared)
                ? !in_array($name, $this->blockedColumns($item), true)
                : in_array($name, $declared, true);
        }

        if ($item->relationLoaded($name)) {
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
        // list is in the key because $sortableRelations may be declared per instance. An instance
        // property rather than a static one: the path comes from the request, so these keys must
        // not outlive the request in a long-running worker.
        //
        // The key carries the CLASS, so only a verdict that depends on nothing but the class may
        // be stored under it. A path that walks a morph does depend on the row - the morph target
        // comes from the row's own type column - so such a verdict is returned and thrown away.
        // Storing it would let the first row asked answer for every later one, in both directions:
        // a true that opens a path the next row does not have, and a false that closes one it does.
        $cacheKey = get_class($model) . '|' . implode(',', $this->declaredSortableRelations($model)) . '|' . $path;

        if (array_key_exists($cacheKey, $this->relationPathVerdicts)) {
            return $this->relationPathVerdicts[$cacheKey];
        }

        $current = $model;
        $segments = explode('.', $path);
        $lastIndex = count($segments) - 1;
        $dependsOnRow = false;

        $remember = function (bool $verdict) use ($cacheKey, &$dependsOnRow): bool {
            return $dependsOnRow ? $verdict : ($this->relationPathVerdicts[$cacheKey] = $verdict);
        };

        foreach ($segments as $index => $segment) {
            if (!$this->isSortableRelation($current, $segment)) {
                return $remember(false);
            }

            $relation = $current->{$segment}();

            if (!$relation instanceof Relation) {
                return $remember(false);
            }

            if ($relation instanceof MorphTo) {
                $dependsOnRow = true;

                // A trailing MorphTo is safe whatever its type: nothing is validated past it, and
                // eager loading a morph is a legitimate thing to ask for. Earlier in the path, an
                // unknown target means every later segment would be proven against the parent
                // model while Eloquent resolves the real one at runtime.
                if ($index !== $lastIndex && $this->isMorphTargetUnknown($current, $relation)) {
                    return $remember(false);
                }
            }

            $current = $relation->getRelated();
        }

        return $remember(true);
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
     * Keep only the relation paths safe to hand to Eloquent for EVERY row of the collection.
     *
     * filterRelationPaths() answers for one model. load() does not: it takes one set of paths and
     * applies it to every row, so a path proven on the row that happens to be first says nothing
     * about the rest - and which row is first is the request's choice, through the sort, the
     * search and the page. With rows of different classes, or of one class but different morph
     * targets, that is the difference between a path Eloquent resolves as a relation and a path
     * it resolves by calling whatever method carries that name on the other target.
     *
     * The surviving set is therefore the intersection: what every row allows. Rows are already
     * limited to the page length here, and a verdict that does not depend on the row is memoised,
     * so the extra walks are the morph ones - the cases this exists for.
     *
     * @param iterable $elements
     * @param array $paths
     * @return array
     */
    protected function filterRelationPathsForEveryRow(iterable $elements, array $paths): array
    {
        $allowed = null;

        foreach ($elements as $element) {
            // A row that is no model cannot prove anything, and load() would be meaningless for
            // it anyway.
            if (!$element instanceof Model) {
                return [];
            }

            $forElement = $this->filterRelationPaths($element, $paths);

            $allowed = is_null($allowed)
                ? $forElement
                : array_values(array_intersect($allowed, $forElement));

            if ($allowed === []) {
                return [];
            }
        }

        return $allowed ?? [];
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
