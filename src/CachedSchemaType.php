<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * Passthrough type that returns a pre-computed array from `toArray()`. Used by
 * SchemaCache to skip rebuilding Data class schemas when the source file is
 * unchanged. Mutating setters propagate into the cached array so post-cache
 * decorators (e.g. GoToDefinitionSchemaExtension appending GitHub links) keep
 * working.
 */
class CachedSchemaType extends OpenApiObjectType
{
    /** @param array<string, mixed> $cachedArray */
    public function __construct(private array $cachedArray)
    {
        parent::__construct();

        if (isset($cachedArray['required']) && is_array($cachedArray['required'])) {
            $this->required = $cachedArray['required'];
        }

        if (! empty($cachedArray['description']) && is_string($cachedArray['description'])) {
            $this->description = $cachedArray['description'];
        }
    }

    public function setDescription(string $description): Type
    {
        $this->cachedArray['description'] = $description;
        $this->description = $description;

        return $this;
    }

    public function format(string $format): Type
    {
        $this->cachedArray['format'] = $format;
        $this->format = $format;

        return $this;
    }

    public function nullable(bool $nullable): Type
    {
        if ($nullable) {
            $existing = $this->cachedArray['type'] ?? 'object';
            $this->cachedArray['type'] = is_array($existing)
                ? array_values(array_unique([...$existing, 'null']))
                : [$existing, 'null'];
        }
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Scramble serializes through serializeAs31()/serializeAs32() (older
     * versions through toArray()), so all three must return the cached form.
     */
    public function toArray()
    {
        return $this->cachedArray;
    }

    public function serializeAs31(): mixed
    {
        return $this->cachedArray;
    }

    public function serializeAs32(): mixed
    {
        return $this->cachedArray;
    }
}
