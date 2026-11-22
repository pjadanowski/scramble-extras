<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property names must match what spatie/laravel-data actually reads (input)
 * and writes (output): MapName(input, output), MapInputName, MapOutputName,
 * on the property or the class, with positional or named arguments.
 */
class NameMappingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('api/mapped', [ReviewController::class, 'storeMapped']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        $result = app(Generator::class)();

        return is_array($result) ? $result : $result->toArray();
    }

    #[Test]
    public function output_schema_uses_output_names(): void
    {
        $properties = array_keys($this->generate()['components']['schemas']['MappedData']['properties']);

        $this->assertEqualsCanonicalizing(
            ['out_both', 'same_both', 'input_only', 'output_only', 'named_out', 'class_mapped'],
            $properties,
        );
    }

    #[Test]
    public function request_body_uses_input_names(): void
    {
        $schema = $this->generate()['paths']['/mapped']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertEqualsCanonicalizing(
            ['in_both', 'same_both', 'input_only', 'outputOnly', 'named_in', 'classMapped'],
            array_keys($schema['properties']),
        );
        $this->assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required']);
    }
}
