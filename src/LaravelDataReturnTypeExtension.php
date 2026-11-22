<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\GenericClassStringType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * Tells Scramble's type inferencer that `Data::from(...)` returns the static
 * Data subclass and `Data::collect(..., PaginatedDataCollection::class)`
 * returns a generic `PaginatedDataCollection<TheData>`.
 *
 * Why: without these hints, controllers like `return CompanyData::from($x)`
 * resolve to an `unknown`/`string` schema in the OpenAPI output.
 */
class LaravelDataReturnTypeExtension implements StaticMethodReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return class_exists($name) && is_a($name, BaseData::class, true);
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        return self::resolveReturnType($event->callee, $event->name, $event->getArg('into', 1));
    }

    public static function resolveReturnType(string $dataClass, string $method, ?Type $intoArg): ?Type
    {
        return match ($method) {
            'from' => new ObjectType($dataClass),
            'collect' => self::collectReturnType($dataClass, $intoArg),
            default => null,
        };
    }

    public static function collectReturnType(string $dataClass, ?Type $intoArg): Type
    {
        $into = self::resolveClassString($intoArg);
        $itemType = new ObjectType($dataClass);

        if ($into !== null) {
            foreach ([
                PaginatedDataCollection::class,
                CursorPaginatedDataCollection::class,
                DataCollection::class,
            ] as $wrapper) {
                if ($into === $wrapper || is_a($into, $wrapper, true)) {
                    return new Generic($wrapper, [$itemType]);
                }
            }
        }

        return new ArrayType(value: $itemType);
    }

    protected static function resolveClassString(?Type $type): ?string
    {
        if ($type instanceof LiteralStringType) {
            return $type->value;
        }

        if ($type instanceof GenericClassStringType) {
            return $type->getValue();
        }

        return null;
    }
}
