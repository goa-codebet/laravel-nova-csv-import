<?php

namespace App\Imports;

use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Laravel\Nova\Actions\ActionResource;
use Laravel\Nova\Http\Requests\NovaRequest;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Importer implements ToCollection, WithValidation, WithHeadingRow, WithBatchInserts, WithChunkReading, SkipsOnFailure, SkipsOnError
{
    use Importable, SkipsFailures, SkipsErrors;

    /** @var Resource */
    protected $resource;
    protected $attributes;
    protected $attribute_map;
    protected $rules;
    protected $model_class;
    protected $indexesPerResource = [
        'App\MbProduct' => 'article-no',
        'App\MbProductGroup' => 'category',
        'App\MbPriceGroup' => 'category',
        'App\MbRsk' => 'rsk-no',
        'App\MbEtimValue' => 'product-article-no',
    ];

    protected $relationResourceTranslations = [
        'App\MbRsk' => 'mb-rsks',
        'App\MbEtimValue' => 'mb-etim-values',
    ];

    protected $filters = [
        'mbEtimValue.',
        'mbRsk.'
    ];

    public function collection(Collection $rows)
    {
        //dd($this->attribute_map);
        
        #dd($firstRow);
        foreach($rows as $row){
            $vals = $this->array_replace_keys($row->toArray(), $this->attribute_map, 1);
            $indexKey = $this->indexesPerResource[$this->model_class];
            if(isset($vals[$indexKey])){
                $indexValue = $vals[$indexKey];
                unset($vals[$indexKey]);

                // Search for relations
                foreach ($this->filters as $filter) {
                    // Setup relations
                    $relations['App\\' .  ucfirst(Str::replaceFirst('.', '', $filter))] = self::rejectAndFlattenRelation($filter, $vals);
                }
                //dd($relations);
                $this->resource->model()::updateOrCreate(
                    [
                        $indexKey => $indexValue
                    ],
                    $vals
                );
                foreach($relations as $relationResourceName => $data){
                    if($data != false){
                        //dd($this->relationResourceTranslations[$relationResourceName]);
                        $relationResource = Nova::resourceInstanceForKey($this->relationResourceTranslations[$relationResourceName]);
                        //dd($relationResource);
                        $indexKey = $this->indexesPerResource[$relationResourceName];
                        if(isset($data[$indexKey])){
                            $indexValue = $data[$indexKey];
                            unset($data[$indexKey]);
                            $relationResource->model()::updateOrCreate(
                                [
                                    $indexKey => $indexValue
                                ],
                                $data
                            );
                        }
                        //dd($data);
                    }
                }
                //dd($model);
            }
            //dd($vals);
        }
        //return $model;
    }

    function array_replace_keys(array $array,array $keys,$filter=false)
    {
        $newArray=[];
        foreach($array as $key=>$value) {   
            if(isset($keys[$key])) {
                $newArray[$keys[$key]]=$value;
            } elseif(!$filter) {
                $newArray[$key]=$value;
            }
        }

        return $newArray;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * @return mixed
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * @param mixed $attributes
     * @return Importer
     */
    public function setAttributes($attributes)
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getAttributeMap()
    {
        return $this->attribute_map;
    }

    /**
     * @param mixed $map
     * @return Importer
     */
    public function setAttributeMap($map)
    {
        $this->attribute_map = $map;

        return $this;
    }

    /**
     * @param mixed $rules
     * @return Importer
     */
    public function setRules($rules)
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getModelClass()
    {
        return $this->model_class;
    }

    /**
     * @param mixed $model_class
     * @return Importer
     */
    public function setModelClass($model_class)
    {
        $this->model_class = $model_class;

        return $this;
    }

    public function setResource($resource)
    {
        $this->resource = $resource;

        return $this;
    }

    private function mapRowDataToAttributes($row)
    {
        //dd($this->attributes);
        $data = [];
        //dd($this->attribute_map);
        if(is_array($this->attributes[0]) && isset($this->attributes[0])){
            foreach ($this->attributes[0] as $field) {
                $data[$field] = null;

                foreach ($this->attribute_map as $column => $attribute) {
                    if (! isset($row[$column]) || $field !== $attribute) {
                        continue;
                    }

                    $data[$field] = $this->preProcessValue($row[$column]);
                }
            }
        } else {
            foreach ($this->attributes as $field) {
                $data[$field] = null;

                foreach ($this->attribute_map as $column => $attribute) {
                    if (! isset($row[$column]) || $field !== $attribute) {
                        continue;
                    }

                    $data[$field] = $this->preProcessValue($row[$column]);
                }
            }
        }
        return $data;
    }

    private function preProcessValue($value)
    {
        switch ($value) {
            case 'FALSE':
                return false;
                break;
            case 'TRUE':
                return true;
                break;
        }

        return $value;
    }

    private static function rejectAndFlattenRelation($filter, $vals)
    {
        // Grab all the currently available inputs
        $input = $vals;

        // Grab all relatable fields according to filter
        foreach($input as $key => $val){
            //dd($filter);
            if(strpos($key, $filter) === false){
                unset($input[$key]);
            } else {
                $relatableFields[str_replace($filter, "", $key)] = $val;
            }
        }
        if(empty($input)){
            return false;
        } else {
            return $relatableFields;
        }
    }
}
