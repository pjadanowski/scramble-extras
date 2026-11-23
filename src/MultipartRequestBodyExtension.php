<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Scramble switches a body to multipart/form-data only when a field has
 * `contentMediaType: application/octet-stream`. A file narrowed by
 * #[Image] (`image/*`) or #[MimeTypes] is still a binary field, so any
 * `format: binary` in a JSON body makes it multipart too - unless the
 * route chose its media type with @requestMediaType.
 */
class MultipartRequestBodyExtension extends OperationExtension
{
    private const JSON = 'application/json';

    private const MULTIPART = 'multipart/form-data';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $body = $operation->requestBodyObject;

        if (! $body instanceof RequestBodyObject || array_keys($body->content) !== [self::JSON]) {
            return;
        }

        if ($routeInfo->phpDoc()->getTagsByName('@requestMediaType') !== []) {
            return;
        }

        if (! $this->hasBinary($body->toArray())) {
            return;
        }

        $body->content = [self::MULTIPART => $body->content[self::JSON]];
    }

    protected function hasBinary(mixed $schema): bool
    {
        if (! is_array($schema)) {
            return false;
        }

        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }

        foreach ($schema as $value) {
            if ($this->hasBinary($value)) {
                return true;
            }
        }

        return false;
    }
}
