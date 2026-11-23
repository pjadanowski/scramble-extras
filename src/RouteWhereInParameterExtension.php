<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Reflection\ReflectionRoute;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Narrows a path parameter to an enum when its route constraint is a plain
 * alternation of literals, which is what Route::whereIn() produces
 * ("alpha|beta"; backed enum cases become their values). Scramble ignores
 * $route->wheres, so without this the parameter is documented as any string.
 * Other where() patterns are real regexes and are left alone.
 *
 * Ported from HishamM1/scramble-extras.
 */
class RouteWhereInParameterExtension extends OperationExtension
{
    private const LITERAL_ALTERNATION = '/^[A-Za-z0-9_\-]+(\|[A-Za-z0-9_\-]+)*$/';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;

        if ($route->wheres === []) {
            return;
        }

        // Route parameter name => the name Scramble documents it under.
        $aliases = ReflectionRoute::createFromRoute($route)->getSignatureParametersMap();
        preg_match_all('/\{(\w+)\??\}/', (string) $route->getDomain(), $domain);

        foreach ($route->wheres as $name => $pattern) {
            if (
                ! is_string($pattern)
                || ! preg_match(self::LITERAL_ALTERNATION, $pattern)
                || ! array_key_exists($name, $aliases)
                || in_array($name, $domain[1], true)
            ) {
                continue;
            }

            foreach ($operation->parameters as $parameter) {
                if ($parameter instanceof Parameter && $parameter->in === 'path' && $parameter->name === $aliases[$name]) {
                    $narrowed = $this->narrowedType($parameter->schema, explode('|', $pattern));

                    if ($narrowed !== null) {
                        $parameter->schema = Schema::fromType($narrowed);
                    }
                }
            }
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function narrowedType(Schema|Reference|null $schema, array $values): ?Type
    {
        $type = $schema instanceof Schema ? $schema->type : $schema;
        $base = $type;

        if ($type instanceof Reference) {
            $resolved = $type->resolve();
            $base = $resolved instanceof Schema ? $resolved->type : $resolved;
        }

        if (! $base instanceof StringType && ! $base instanceof IntegerType) {
            return null;
        }

        $isInteger = $base instanceof IntegerType;

        // "007" would round-trip to 7, a value the route never accepts.
        if ($isInteger && array_filter($values, fn (string $value) => (string) (int) $value !== $value) !== []) {
            return null;
        }

        if ($type instanceof Reference) {
            $narrowed = $isInteger ? new IntegerType : new StringType;
            // Older Scramble has null rather than MissingValue for "no default".
            if ($type->default !== null) {
                $narrowed->default = $type->default;
            }
            $narrowed->setDescription($type->description);
        } else {
            $narrowed = clone $type; // Type::clone() is missing on older Scramble.
        }

        return $narrowed->enum($isInteger ? array_map('intval', $values) : $values);
    }
}
