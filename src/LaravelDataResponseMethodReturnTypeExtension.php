<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Contracts\BaseDataCollectable;

/**
 * $data->toResponse($request) returns a JsonResponse carrying the
 * transformed Data or collection. Typing it as JsonResponse<static, 200>
 * lets Scramble document the body with the regular Data/collection schema
 * (and keeps a later ->setStatusCode() working) instead of a bare string.
 *
 * Ported from HishamM1/scramble-extras.
 */
class LaravelDataResponseMethodReturnTypeExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(BaseData::class) || $type->isInstanceOf(BaseDataCollectable::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        if ($event->name !== 'toResponse') {
            return null;
        }

        return new Generic(JsonResponse::class, [
            $event->getInstance(),
            new LiteralIntegerType(200),
            new KeyedArrayType,
        ]);
    }
}
