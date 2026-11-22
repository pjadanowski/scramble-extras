<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * exclusiveMinimum / multipleOf live on ConstrainedNumberType. They must make
 * it into the generated document, not only into the type's toArray():
 * Scramble serializes through serializeAs31()/serializeAs32().
 */
class NumericConstraintsSerializationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/constraints', [ReviewController::class, 'constraints']);
    }

    #[Test]
    public function exclusive_minimum_and_multiple_of_reach_the_document(): void
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        $properties = $openApi['components']['schemas']['ConstraintsData']['properties'];

        $this->assertSame(0, $properties['quantity']['exclusiveMinimum'] ?? null);
        $this->assertSame(5, $properties['step']['multipleOf'] ?? null);
    }
}
