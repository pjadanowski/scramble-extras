<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\ReviewController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * config('data.name_mapping_strategy') applies to every Data class without
 * a mapping attribute of its own.
 */
class GlobalNameMappingStrategyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('data.name_mapping_strategy', [
            'input' => SnakeCaseMapper::class,
            'output' => SnakeCaseMapper::class,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->post('api/plain', [ReviewController::class, 'storePlain']);
    }

    #[Test]
    public function global_strategy_applies_to_input_and_output(): void
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        $this->assertSame(['first_name'], array_keys($openApi['components']['schemas']['PlainData']['properties']));
        $this->assertSame(
            ['first_name'],
            array_keys($openApi['paths']['/plain']['post']['requestBody']['content']['application/json']['schema']['properties']),
        );
    }
}
