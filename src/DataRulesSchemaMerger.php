<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\Types\UnknownType as OpenApiUnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\FormRequestParametersExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\DeepParametersMerger;
use Dedoc\Scramble\Support\RouteInfo;
use Throwable;

/**
 * Folds the constraints of a Data class's rules() into its input schema.
 *
 * The rules are evaluated and mapped by Scramble's own form request
 * machinery, so every rule Scramble understands is covered. The Data schema
 * stays the source of truth for the shape (names, types, nullability); rules
 * only fill in constraints the property doesn't have yet (length, range,
 * enum, pattern, format, ...) and mark properties they require. Rule keys
 * that aren't properties are ignored, since they never reach the Data object.
 */
class DataRulesSchemaMerger
{
    /**
     * Constraint fields copied from a rule schema when the property has none.
     */
    private const CONSTRAINTS = [
        'format', 'pattern', 'enum', 'min', 'max', 'minItems', 'maxItems',
        'contentMediaType', 'description',
    ];

    public function __construct(protected TypeTransformer $openApiTransformer) {}

    public function merge(OpenApiObjectType $schema, string $dataClass, RouteInfo $routeInfo): void
    {
        $rules = $this->rulesSchema($dataClass, $routeInfo);

        if ($rules !== null) {
            $this->mergeObject($schema, $rules);
        }
    }

    protected function rulesSchema(string $dataClass, RouteInfo $routeInfo): ?OpenApiObjectType
    {
        if (! method_exists($dataClass, 'rules')) {
            return null;
        }

        try {
            $result = app()
                ->make(FormRequestParametersExtractor::class, ['openApiTransformer' => $this->openApiTransformer])
                ->extractFormRequestParameters($dataClass, $routeInfo);
        } catch (Throwable) {
            // rules() that can't run outside a request: document without them.
            return null;
        }

        $object = new OpenApiObjectType;

        foreach ((new DeepParametersMerger(collect($result->parameters)))->handle() as $parameter) {
            if (! $parameter->schema instanceof Schema) {
                continue;
            }

            $object->addProperty($parameter->name, $parameter->schema->type);

            if ($parameter->required) {
                $object->addRequired([$parameter->name]);
            }
        }

        return $object;
    }

    protected function mergeObject(OpenApiObjectType $target, OpenApiObjectType $rules): void
    {
        foreach ($rules->properties as $name => $ruleType) {
            $property = $target->hasProperty($name) ? $target->getProperty($name) : null;

            if ($property === null) {
                continue;
            }

            $target->addProperty($name, $this->mergeType($property, $ruleType));

            if (in_array($name, $rules->required, true)) {
                $target->addRequired([$name]);
            }
        }

        $target->setRequired(array_values(array_unique($target->required)));
    }

    protected function mergeType(OpenApiType $target, OpenApiType $rules): OpenApiType
    {
        if ($target instanceof OpenApiUnknownType) {
            return $rules;
        }

        if (! $this->sameKind($target, $rules)) {
            return $target;
        }

        if ($target instanceof OpenApiObjectType && $rules instanceof OpenApiObjectType) {
            $this->mergeObject($target, $rules);
        }

        if ($target instanceof OpenApiArrayType && $rules instanceof OpenApiArrayType
            && $target->items instanceof OpenApiType && $rules->items instanceof OpenApiType) {
            $target->setItems($this->mergeType($target->items, $rules->items));
        }

        foreach (self::CONSTRAINTS as $field) {
            if (property_exists($target, $field) && property_exists($rules, $field)
                && $this->isEmpty($target->{$field}) && ! $this->isEmpty($rules->{$field})) {
                $target->{$field} = $rules->{$field};
            }
        }

        return $target;
    }

    protected function sameKind(OpenApiType $target, OpenApiType $rules): bool
    {
        return $target->type === $rules->type
            || ($target->type === 'integer' && $rules->type === 'number');
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
