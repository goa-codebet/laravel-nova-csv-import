<?php

namespace SimonHamp\LaravelNovaCsvImport\Http\Controllers;

use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Laravel\Nova\Rules\Relatable;
use Laravel\Nova\Actions\ActionResource;
use Laravel\Nova\Http\Requests\NovaRequest;
use SimonHamp\LaravelNovaCsvImport\Importer;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Field;

class ImportController
{
    /**
     * @var Importer
     */
    protected $importer;

    public function __construct()
    {
        $class = config('nova-csv-importer.importer');
        $this->importer = new $class;
    }

    public function preview(NovaRequest $request, $file)
    {
        $import = $this->importer
            ->toCollection($this->getFilePath($file), null)
            ->first();

        $headings = $import->first()->keys();

        $total_rows = $import->count();

        $sample = $import->take(10)->all();

        $resources = $this->getAvailableResourcesForImport($request); 

        $fields = $resources->mapWithKeys(function ($resource) use ($request) {
            return $this->getAvailableFieldsForImport($resource, $request);
        });

        $resources = $resources->mapWithKeys(function ($resource) {
            return [$resource::uriKey() => $resource::label()];
        });

        return response()->json(compact('sample', 'resources', 'fields', 'total_rows', 'headings'));
    }

    public function getAvailableFieldsForImport(String $resource, $request)
    {
        $novaResource = new $resource(new $resource::$model);
        $fieldsCollection = collect($novaResource->creationFields($request));
        $excludedFields = $novaResource::excludeAttributesFromImport($request);
        
        $fields = [];
        if(is_array($fieldsCollection[0]) && isset($fieldsCollection[0]['fields']) && is_array($fieldsCollection[0]['fields'])){
            foreach($fieldsCollection[0]['fields'] as $field){
                if(!in_array($field->attribute, $excludedFields)){
                    $fields[] = [
                        'name' => $field->name,
                        'attribute' => $field->attribute
                    ];
                }
            }
        } else {
            foreach($fieldsCollection as $field){
                if(is_object($field)){
                    if(!in_array($field->attribute, $excludedFields)){
                        $fields[] = [
                            'name' => $field->name,
                            'attribute' => $field->attribute
                        ];
                    }
                }
            }
        }

       return [$novaResource->uriKey() => $fields];
    }

    public function getAvailableResourcesForImport(NovaRequest $request) {

        $novaResources = collect(Nova::authorizedResources($request));

        return $novaResources->filter(function ($resource) use ($request) {
                    if ($resource === ActionResource::class) {
                        return false;
                    }

                    if (!isset($resource::$model)) {
                        return false;
                    }
                    
                    $resourceReflection = (new \ReflectionClass((string) $resource));
                    
                    if ($resourceReflection->hasMethod('canImportResource')) {
                        return $resource::canImportResource($request);
                    }

                    $static_vars = $resourceReflection->getStaticProperties();

                    if (isset($static_vars['canImportResource'])) {
                        return true;
                    } else {
                        return false;
                    }

                    return isset($static_vars['canImportResource']) && $static_vars['canImportResource'];
                });
    }

    public function import(NovaRequest $request, $file)
    {
        $resource_name = $request->input('resource');
        $request->route()->setParameter('resource', $resource_name);
        $resource = Nova::resourceInstanceForKey($resource_name);

        $attribute_map = $request->input('mappings');
        $model_class = get_class($resource->resource);
        $rules = $this->extractValidationRules($request, $resource)->toArray();
        $attributes = $resource->creationFields($request)->pluck('attribute');
        if(count($attributes) <= 1){
            $attributes[0] = array_keys($rules);
        }

        $this->importer
            ->setResource($resource)
            ->setAttributes($attributes)
            ->setAttributeMap($attribute_map)
            ->setRules($rules)
            ->setModelClass($model_class)
            ->import($this->getFilePath($file), null);

        if (! $this->importer->failures()->isEmpty() || ! $this->importer->errors()->isEmpty()) {
            return response()->json(['result' => 'failure', 'errors' => $this->importer->errors(), 'failures' => $this->importer->failures()]);
        }

        return response()->json(['result' => 'success']);
    }

    protected function extractValidationRules($request, Resource $resource)
    {
        return collect($resource::rulesForCreation($request))->mapWithKeys(function ($rule, $key) {
            foreach ($rule as $i => $r) {
                if (! is_object($r)) {
                    continue;
                }

                // Make sure relation checks start out with a clean query
                if (is_a($r, Relatable::class)) {
                    $rule[$i] = function () use ($r) {
                        $r->query = $r->query->newQuery();
                        return $r;
                    };
                }
            }

            return [$key => $rule];
        });
    }

    protected function getFilePath($file)
    {
        return storage_path("nova/laravel-nova-import-csv/tmp/{$file}");
    }

    private function responseError($error)
    {
        throw ValidationException::withMessages([
            0 => [$error],
        ]);
    }
}
