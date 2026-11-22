<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\FormRequestParametersExtractor;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\RulesController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Scramble treats any class with a rules() method as a form request, so a
 * Data class with rules() got a second, rules-only body: an allOf of a
 * stray FQCN-named component and our schema. The constraints from rules()
 * are folded into the Data schema instead.
 */
class DataRulesTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('api/ruled', [RulesController::class, 'store']);
        $router->get('api/ruled', [RulesController::class, 'search']);
        $router->post('api/context-ruled', [RulesController::class, 'context']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(FormRequestParametersExtractor::class, 'ignoreInstanceOf')) {
            $this->markTestSkipped('This Scramble version cannot exclude Data from its form request extractor.');
        }
    }

    // Scramble maps numeric rule arguments to floats (max:20 => 20.0), as
    // it does for form requests, hence assertEquals for the bounds.

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $result = app(Generator::class)();

        return is_array($result) ? $result : $result->toArray();
    }

    #[Test]
    public function the_body_is_one_inline_schema_without_a_stray_component(): void
    {
        $document = $this->document();
        $schema = $document['paths']['/ruled']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('allOf', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['title', 'tags', 'code', 'age', 'address'], array_keys($schema['properties']));
        $this->assertSame([], array_values(array_filter(
            array_keys($document['components']['schemas'] ?? []),
            fn (string $name) => str_contains($name, '.'),
        )));
    }

    #[Test]
    public function rules_add_their_constraints_to_the_properties(): void
    {
        $properties = $this->document()['paths']['/ruled']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertEquals(20, $properties['title']['maxLength']);
        $this->assertEquals(1, $properties['tags']['minItems']);
        $this->assertSame(['a', 'b'], $properties['tags']['items']['enum']);
        $this->assertSame('^[A-Z]+$', $properties['code']['pattern']);
        $this->assertEquals(18, $properties['age']['minimum']);
        $this->assertEquals(50, $properties['address']['properties']['city']['maxLength']);
    }

    #[Test]
    public function query_parameters_get_the_constraints_too(): void
    {
        $parameters = collect($this->document()['paths']['/ruled']['get']['parameters'])->keyBy('name');

        $this->assertEquals(20, $parameters['title']['schema']['maxLength']);
        $this->assertEquals(18, $parameters['age']['schema']['minimum']);
        $this->assertFalse($parameters->has('not_a_property'));
    }

    #[Test]
    public function rules_taking_a_validation_context_are_read(): void
    {
        $schema = $this->document()['paths']['/context-ruled']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('allOf', $schema);
        $this->assertEquals(10, $schema['properties']['name']['maxLength']);
    }
}
