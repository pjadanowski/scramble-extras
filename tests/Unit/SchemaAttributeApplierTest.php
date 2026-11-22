<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Unit;

use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use PawelJadanowski\ScrambleExtras\AttributeAnalysis;
use PawelJadanowski\ScrambleExtras\ConstrainedNumberType;
use PawelJadanowski\ScrambleExtras\PatternedStringType;
use PawelJadanowski\ScrambleExtras\SchemaAttributeApplier;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ValidationFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class SchemaAttributeApplierTest extends TestCase
{
    private function analyze(string $property, OpenApiType $base): AttributeAnalysis
    {
        $reflection = new ReflectionProperty(ValidationFixtures::class, $property);

        return (new SchemaAttributeApplier)->analyze($reflection, $base);
    }

    #[Test]
    public function email_sets_format(): void
    {
        $analysis = $this->analyze('email', new StringType);

        $this->assertSame('email', $analysis->type->toArray()['format'] ?? null);
    }

    #[Test]
    public function url_sets_uri_format(): void
    {
        $analysis = $this->analyze('url', new StringType);

        $this->assertSame('uri', $analysis->type->toArray()['format'] ?? null);
    }

    #[Test]
    public function min_on_string_sets_min_length(): void
    {
        $analysis = $this->analyze('minString', new StringType);

        $this->assertSame(3, $analysis->type->toArray()['minLength'] ?? null);
    }

    #[Test]
    public function max_on_string_sets_max_length(): void
    {
        $analysis = $this->analyze('maxString', new StringType);

        $this->assertSame(10, $analysis->type->toArray()['maxLength'] ?? null);
    }

    #[Test]
    public function between_on_string_sets_both_bounds(): void
    {
        $array = $this->analyze('betweenString', new StringType)->type->toArray();

        $this->assertSame(2, $array['minLength'] ?? null);
        $this->assertSame(8, $array['maxLength'] ?? null);
    }

    #[Test]
    public function min_on_integer_sets_minimum(): void
    {
        $analysis = $this->analyze('minInt', new IntegerType);

        $this->assertSame(5, $analysis->type->toArray()['minimum'] ?? null);
    }

    #[Test]
    public function greater_than_emits_exclusive_minimum_and_keeps_integer(): void
    {
        $analysis = $this->analyze('positiveInt', new IntegerType);
        $array = $analysis->type->toArray();

        $this->assertInstanceOf(ConstrainedNumberType::class, $analysis->type);
        $this->assertSame('integer', $array['type'] ?? null);
        $this->assertSame(0, $array['exclusiveMinimum'] ?? null);
    }

    #[Test]
    public function less_than_emits_exclusive_maximum(): void
    {
        $array = $this->analyze('belowHundred', new IntegerType)->type->toArray();

        $this->assertSame(100, $array['exclusiveMaximum'] ?? null);
    }

    #[Test]
    public function multiple_of(): void
    {
        $array = $this->analyze('multipleOfFive', new IntegerType)->type->toArray();

        $this->assertSame(5, $array['multipleOf'] ?? null);
    }

    #[Test]
    public function regex_sets_pattern_without_delimiters(): void
    {
        $analysis = $this->analyze('regexed', new StringType);

        $this->assertInstanceOf(PatternedStringType::class, $analysis->type);
        $this->assertSame('^[a-z]+$', $analysis->type->toArray()['pattern'] ?? null);
    }

    #[Test]
    public function alpha_dash_sets_pattern(): void
    {
        $array = $this->analyze('slug', new StringType)->type->toArray();

        $this->assertSame('^[A-Za-z0-9_-]+$', $array['pattern'] ?? null);
    }

    #[Test]
    public function starts_with_builds_alternation_pattern(): void
    {
        $array = $this->analyze('prefixed', new StringType)->type->toArray();

        $this->assertSame('^(foo|bar)', $array['pattern'] ?? null);
    }

    #[Test]
    public function in_emits_enum(): void
    {
        $array = $this->analyze('inList', new StringType)->type->toArray();

        $this->assertSame(['a', 'b', 'c'], $array['enum'] ?? null);
    }

    #[Test]
    public function enum_class_emits_backed_values(): void
    {
        $array = $this->analyze('enumBacked', new StringType)->type->toArray();

        $this->assertSame(['active', 'inactive', 'pending'], $array['enum'] ?? null);
    }

    #[Test]
    public function digits_sets_length_and_pattern(): void
    {
        $array = $this->analyze('pin', new StringType)->type->toArray();

        $this->assertSame(4, $array['minLength'] ?? null);
        $this->assertSame(4, $array['maxLength'] ?? null);
        $this->assertSame('^\d{4}$', $array['pattern'] ?? null);
    }

    #[Test]
    public function image_sets_content_media_type(): void
    {
        $array = $this->analyze('avatar', new StringType)->type->toArray();

        $this->assertSame('image/*', $array['contentMediaType'] ?? null);
        $this->assertSame('binary', $array['contentEncoding'] ?? null);
    }

    #[Test]
    public function file_sets_octet_stream(): void
    {
        $array = $this->analyze('attachment', new StringType)->type->toArray();

        $this->assertSame('application/octet-stream', $array['contentMediaType'] ?? null);
    }

    #[Test]
    public function required_forces_required(): void
    {
        $this->assertTrue($this->analyze('forcedRequired', new StringType)->forceRequired);
    }

    #[Test]
    public function sometimes_forces_not_required(): void
    {
        $this->assertFalse($this->analyze('forcedOptional', new StringType)->forceRequired);
    }

    #[Test]
    public function confirmed_adds_note(): void
    {
        $notes = $this->analyze('passwordConfirmed', new StringType)->notes;

        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('confirmed', strtolower($notes[0]));
    }

    #[Test]
    public function same_adds_note_referencing_field(): void
    {
        $notes = $this->analyze('sameAsPassword', new StringType)->notes;

        $this->assertStringContainsString('password', implode(' ', $notes));
    }

    #[Test]
    public function exists_adds_note(): void
    {
        $notes = $this->analyze('existingUser', new IntegerType)->notes;

        $this->assertStringContainsString('users', implode(' ', $notes));
    }

    #[Test]
    public function required_if_adds_note(): void
    {
        $notes = $this->analyze('conditionallyRequired', new StringType)->notes;

        $this->assertStringContainsString('Required when', implode(' ', $notes));
    }

    #[Test]
    public function nullable_marks_type_nullable(): void
    {
        $analysis = $this->analyze('explicitlyNullable', new StringType);
        $array = $analysis->type->toArray();
        $type = $array['type'] ?? null;

        $this->assertTrue(
            $type === 'null'
            || (is_array($type) && in_array('null', $type, true))
            || ($array['nullable'] ?? false) === true,
            'Expected the type to be marked nullable.',
        );
    }

    #[Test]
    public function plain_property_has_no_notes_or_overrides(): void
    {
        $analysis = $this->analyze('plain', new StringType);

        $this->assertSame([], $analysis->notes);
        $this->assertNull($analysis->forceRequired);
    }

    #[Test]
    public function example_and_default_are_read_from_phpdoc(): void
    {
        $reflection = new ReflectionProperty(\PawelJadanowski\ScrambleExtras\Tests\Fixtures\UserData::class, 'name');
        $type = new StringType;

        (new SchemaAttributeApplier)->applyExamplesFromPhpDoc($reflection, $type);

        // Type::example() is a scalar `example` key on dedoc/scramble 0.13.0
        // (this package's floor version), but got deprecated somewhere before
        // 0.13.35 (latest at time of writing): toArray() now folds it into the
        // plural `examples` list instead. ^0.13.0 spans both shapes - this
        // package's own CI matrix runs prefer-lowest against 0.13.0 alongside
        // prefer-stable against latest, so the test has to tolerate either.
        $array = $type->toArray();

        if (isset($array['examples'])) {
            $this->assertSame(['Ada Lovelace'], $array['examples']);
        } else {
            $this->assertSame('Ada Lovelace', $array['example'] ?? null);
        }
    }

    #[Test]
    public function min_and_max_digits_on_integer_bound_the_value(): void
    {
        $this->assertSame(100, $this->analyze('minDigitsInt', new IntegerType)->type->toArray()['minimum'] ?? null);
        $this->assertSame(9999, $this->analyze('maxDigitsInt', new IntegerType)->type->toArray()['maximum'] ?? null);
    }

    #[Test]
    public function digits_on_integer_bound_the_value(): void
    {
        $exact = $this->analyze('digitsInt', new IntegerType)->type->toArray();
        $this->assertSame([1000, 9999], [$exact['minimum'] ?? null, $exact['maximum'] ?? null]);

        $between = $this->analyze('digitsBetweenInt', new IntegerType)->type->toArray();
        $this->assertSame([10, 9999], [$between['minimum'] ?? null, $between['maximum'] ?? null]);
    }

    #[Test]
    public function min_max_and_size_on_array_bound_the_item_count(): void
    {
        $this->assertSame(1, $this->analyze('minItems', new ArrayType)->type->toArray()['minItems'] ?? null);
        $this->assertSame(5, $this->analyze('maxItems', new ArrayType)->type->toArray()['maxItems'] ?? null);

        $size = $this->analyze('sizeItems', new ArrayType)->type->toArray();
        $this->assertSame([2, 2], [$size['minItems'] ?? null, $size['maxItems'] ?? null]);
    }

    #[Test]
    public function distinct_on_array_sets_unique_items(): void
    {
        if (! method_exists(ArrayType::class, 'setUniqueItems')) {
            $this->markTestSkipped('This dedoc/scramble version has no uniqueItems support.');
        }

        $this->assertTrue($this->analyze('distinctItems', new ArrayType)->type->toArray()['uniqueItems'] ?? false);
    }

    #[Test]
    public function max_on_a_file_is_kilobytes_not_string_length(): void
    {
        foreach (['upload', 'fileByAttribute'] as $property) {
            $analysis = $this->analyze($property, new StringType);

            $this->assertArrayNotHasKey('maxLength', $analysis->type->toArray(), $property);
            $this->assertStringContainsString('kilobytes', implode(' ', $analysis->notes), $property);
        }
    }

    #[Test]
    public function regex_with_flags_becomes_a_note_not_a_wrong_pattern(): void
    {
        $analysis = $this->analyze('caseInsensitiveRegex', new StringType);

        $this->assertArrayNotHasKey('pattern', $analysis->type->toArray());
        $this->assertStringContainsString('/^[a-z]+$/i', implode(' ', $analysis->notes));
    }

    #[Test]
    public function regex_with_bracket_delimiters_is_unwrapped(): void
    {
        $this->assertSame('^\d+$', $this->analyze('braceDelimitedRegex', new StringType)->type->toArray()['pattern'] ?? null);
    }

    #[Test]
    public function date_format_maps_to_openapi_formats_or_a_note(): void
    {
        $this->assertSame('date', $this->analyze('isoDate', new StringType)->type->toArray()['format'] ?? null);
        $this->assertSame('date-time', $this->analyze('isoDateTime', new StringType)->type->toArray()['format'] ?? null);

        $custom = $this->analyze('customDate', new StringType);
        $this->assertArrayNotHasKey('format', $custom->type->toArray());
        $this->assertStringContainsString('d/m/Y', implode(' ', $custom->notes));
    }
}
