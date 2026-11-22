<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;

/**
 * Result of analyzing a Data property's attributes — carries the (possibly
 * replaced) OpenAPI type, override flags for required/optional status, and
 * any human-readable constraint notes that don't have a native OpenAPI field.
 */
class AttributeAnalysis
{
    /** @var string[] */
    public array $notes = [];

    public ?bool $forceRequired = null;

    /**
     * Whether the property is an uploaded file: Laravel's size rules
     * (min/max/size/between) then count kilobytes, not characters.
     */
    public bool $isFile = false;

    public function __construct(public OpenApiType $type)
    {
    }

    public function addNote(string $note): void
    {
        $note = trim($note);
        if ($note !== '' && ! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
