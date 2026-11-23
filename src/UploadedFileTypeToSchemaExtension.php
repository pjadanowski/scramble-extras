<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An uploaded file is a binary form field, documented the way Scramble
 * documents the `file` validation rule (which also makes Scramble pick
 * multipart/form-data for the body).
 */
class UploadedFileTypeToSchemaExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(UploadedFile::class);
    }

    public function toSchema(Type $type, ?OpenApiType $previousExtensionResult = null): OpenApiType
    {
        return (new OpenApiStringType)
            ->contentMediaType('application/octet-stream')
            ->format('binary');
    }
}
