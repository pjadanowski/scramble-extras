<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Unit;

use Dedoc\Scramble\Support\Generator\Types\StringType;
use PawelJadanowski\ScrambleExtras\CachedSchemaType;
use PawelJadanowski\ScrambleExtras\ConstrainedNumberType;
use PawelJadanowski\ScrambleExtras\PatternedStringType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SupportTypesTest extends TestCase
{
    #[Test]
    public function constrained_number_type_serializes_exclusive_bounds(): void
    {
        $type = (new ConstrainedNumberType('integer'))
            ->setExclusiveMin(0)
            ->setExclusiveMax(100)
            ->setMultipleOf(5);

        $array = $type->toArray();

        $this->assertSame('integer', $array['type']);
        $this->assertSame(0, $array['exclusiveMinimum']);
        $this->assertSame(100, $array['exclusiveMaximum']);
        $this->assertSame(5, $array['multipleOf']);
    }

    #[Test]
    public function constrained_number_type_omits_null_bounds(): void
    {
        $array = (new ConstrainedNumberType('number'))->toArray();

        $this->assertArrayNotHasKey('exclusiveMinimum', $array);
        $this->assertArrayNotHasKey('multipleOf', $array);
    }

    #[Test]
    public function patterned_string_type_renders_pattern(): void
    {
        $array = (new PatternedStringType)->setPattern('^[a-z]+$')->toArray();

        $this->assertSame('^[a-z]+$', $array['pattern']);
    }

    #[Test]
    public function patterned_string_type_without_pattern_has_no_key(): void
    {
        $array = (new PatternedStringType)->toArray();

        $this->assertArrayNotHasKey('pattern', $array);
    }

    #[Test]
    public function cached_schema_type_returns_cached_array(): void
    {
        $cached = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];

        $type = new CachedSchemaType($cached);

        $this->assertSame($cached, $type->toArray());
        $this->assertSame(['id'], $type->required);
    }

    #[Test]
    public function cached_schema_type_set_description_propagates_to_array(): void
    {
        $type = new CachedSchemaType(['type' => 'object']);
        $type->setDescription('Hello');

        $this->assertSame('Hello', $type->toArray()['description']);
    }

    #[Test]
    public function cached_schema_type_nullable_adds_null_to_type(): void
    {
        $type = new CachedSchemaType(['type' => 'object']);
        $type->nullable(true);

        $this->assertSame(['object', 'null'], $type->toArray()['type']);
    }
}
