<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\UserController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * laravel-data always writes every property except Optional/Lazy ones (a
 * nullable property is written as null, a defaulted one with its value), so
 * in a response they are required. In a request body, nullable and
 * defaulted properties may be omitted.
 */
class OutputRequiredTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('api/users', [UserController::class, 'store']);
    }

    #[Test]
    public function nullable_and_defaulted_properties_are_required_in_output_only(): void
    {
        $result = app(Generator::class)();
        $openApi = is_array($result) ? $result : $result->toArray();

        $output = $openApi['components']['schemas']['UserData']['required'];
        $input = $openApi['paths']['/users']['post']['requestBody']['content']['application/json']['schema']['required'];

        // `?string $bio = null`
        $this->assertContains('bio', $output);
        $this->assertNotContains('bio', $input);

        // `string|Optional $nickname`
        $this->assertNotContains('nickname', $output);
        $this->assertNotContains('nickname', $input);
    }
}
