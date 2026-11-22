<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * A route returning a PaginatedDataCollection reads the page to show from
 * the `page` query parameter (Laravel's paginator default), and one
 * returning a CursorPaginatedDataCollection reads `cursor`. A parameter the
 * operation already has (#[QueryParameter], jsonPaginate()'s page[number])
 * is left as it is.
 *
 * Idea from HishamM1/scramble-extras.
 */
class PaginationParametersOperationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (strtolower($operation->method) !== 'get') {
            return;
        }

        $returnType = $routeInfo->getReturnType();

        if (! $returnType instanceof Type) {
            return;
        }

        if ($this->returns($returnType, PaginatedDataCollection::class)) {
            $this->addParameter($operation, 'page', (new OpenApiIntegerType)->setMin(1));
        } elseif ($this->returns($returnType, CursorPaginatedDataCollection::class)) {
            $this->addParameter($operation, 'cursor', new OpenApiStringType);
        }
    }

    /**
     * Whether the route returns $class: directly, as one member of a union,
     * or as the body of a JsonResponse (->toResponse($request)).
     */
    protected function returns(Type $type, string $class): bool
    {
        if ($type instanceof Union) {
            foreach ($type->types as $member) {
                if ($this->returns($member, $class)) {
                    return true;
                }
            }

            return false;
        }

        if (! $type instanceof ObjectType) {
            return false;
        }

        if ($type->isInstanceOf($class)) {
            return true;
        }

        return $type instanceof Generic
            && $type->isInstanceOf(JsonResponse::class)
            && isset($type->templateTypes[0])
            && $this->returns($type->templateTypes[0], $class);
    }

    protected function addParameter(Operation $operation, string $name, OpenApiType $schema): void
    {
        foreach ($operation->parameters as $existing) {
            if ($existing instanceof Parameter && $existing->in === 'query'
                && ($existing->name === $name || str_starts_with($existing->name, $name.'['))) {
                return;
            }
        }

        $operation->addParameters([
            (new Parameter($name, 'query'))->setSchema(Schema::fromType($schema)),
        ]);
    }
}
