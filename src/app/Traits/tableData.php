<?php


namespace PatrykSawicki\Helper\app\Traits;


use Illuminate\Http\Request;
use function PHPUnit\Framework\stringEndsWith;

/*
 * Trait for getting data by api to data tables.
 * */
trait tableData
{
    public function getTableData(Request $request, $elements, bool $sort=true):array
    {
        $start=$request->start ?? 0;
        $length=$request->length ?? 100;
        $sortDir=$request->order[0]['dir'] ?? 'asc';
        $sortColumn=(isset($request->order[0]['column']) ? $request->columns[$request->order[0]['column']]['name'] : 'id') ?? 'id';
        $draw=$request->draw ?? 1;
        $search=($request->has('search') && !empty($request->search['value'])) ? trim(json_encode(mb_strtolower($request->search['value'], 'UTF-8')), '"') : null;

        $total=$elements->count();

        /*Sort*/
        if($sort)
            $elements = ($sortDir == 'asc') ? $elements->sortBy($sortColumn) : $elements->sortByDesc($sortColumn);

        /*Search*/
        if(!is_null($search))
        {
            $elements=$elements->filter(function ($item) use ($request, $search) {
                $test=mb_strtolower($item->__toString(), 'UTF-8');
                $value=trim(json_encode(mb_strtolower($request->search['value'], 'UTF-8')), '"');
                $value2=trim(mb_strtolower($request->search['value'], 'UTF-8'), '"');
                $value3=trim(json_encode(mb_strtoupper($request->search['value'], 'UTF-8')), '"');
                return (str_contains(strip_tags($test), mb_strtolower($value, 'UTF-8')) ||
                        str_contains(strip_tags($test), mb_strtolower($value2, 'UTF-8')) ||
                        str_contains(strip_tags(strtolower($item->toJson())), $value) ||
                        str_contains(strip_tags($item->toJson()), $value3));
            });
        }

        /*Column Search*/
        if($request->has('columns'))
            foreach ($request->columns as $column)
            {
                $colName=$column['name'];

                if ($column['searchable'] == 'true' && !empty($column['search']['value'])) {
                    $elements = $elements->filter(function ($item) use ($column, $colName) {
                        return $this->filterTableData($item, $column, $colName);
                    });
                }
            }

        $filtered=$elements->count();

        /*Start*/
        $elements=$elements->slice($start);

        /*Take*/
        $elements=$elements->take($length);
        return [$elements, $draw, $total, $filtered];
    }

    protected function filterTableData($item, $column, $colName): bool
    {
        if(is_null($item))
            return false;

        if(str_contains($colName, '.'))
        {
            [$model, $column['name']]=explode('.', $colName, 2);
            return $this->filterTableData($item->{$model}, $column, $column['name']);
        }

        $testValue = stringEndsWith('()')->evaluate($colName, '', true) ?
            mb_strtolower($item->{trim($colName, '()')}(), 'UTF-8') :
            mb_strtolower($item->{$colName}, 'UTF-8');

        $value = trim(json_encode(mb_strtolower($column['search']['value'], 'UTF-8')), '"');
        $value2 = trim(mb_strtolower($column['search']['value'], 'UTF-8'), '"');
        $value3 = trim(json_encode(mb_strtoupper($column['search']['value'], 'UTF-8')), '"');

        return (str_contains(strip_tags($testValue), mb_strtolower($value, 'UTF-8')) ||
                str_contains(strip_tags($testValue), mb_strtolower($value2, 'UTF-8')) ||
                (is_object($item->{$colName}) && str_contains(strip_tags(strtolower($item->{$colName}->toJson())), $value)) ||
                (is_object($item->{$colName}) && str_contains(strip_tags($item->{$colName}->toJson()), $value3)));
    }
}
