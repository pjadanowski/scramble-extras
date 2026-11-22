<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\UniqueNameOptions;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * When a route is documented once per HTTP method (PUT and PATCH, see
 * RouteMethodsResolver), all its operations ask for the same operation id
 * and Scramble falls back to the long class-based id with a counter
 * (…user.update_0, …user.update_1). The route's first method keeps the
 * plain id; every other method gets its name appended (user.update.patch).
 *
 * Ported from HishamM1/scramble-extras.
 */
class MethodAwareOperationIdExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $methods = array_values(array_diff(array_map('strtolower', $routeInfo->route->methods()), ['head']));
        $options = $operation->getAttribute('operationId');

        if (count($methods) < 2 || $operation->method === $methods[0] || ! $options instanceof UniqueNameOptions) {
            return;
        }

        $operation->setAttribute('operationId', new UniqueNameOptions(
            eloquent: $options->eloquent === null ? null : $options->eloquent.$options->separator.$operation->method,
            unique: [...$options->unique, $operation->method],
            separator: $options->separator,
            // The method is now the last part; keep the parts the id had.
            fallbackEloquentPartsCount: $options->fallbackEloquentPartsCount + 1,
        ));
    }
}
