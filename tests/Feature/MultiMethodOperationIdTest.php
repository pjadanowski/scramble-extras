<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\UserController;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Documenting PATCH next to PUT gives one route two operations. Scramble
 * resolves the clashing operation ids by falling back to the full
 * class-based name with a counter (…users.update_0 / …update_1); the
 * primary method keeps the plain id and the others get the method suffix.
 */
class MultiMethodOperationIdTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->match(['put', 'patch'], 'api/users/{id}', [UserController::class, 'update']);
        $router->get('api/users/{id}', [UserController::class, 'show']);
    }

    #[Test]
    public function the_secondary_methods_get_the_http_method_suffix(): void
    {
        if (! method_exists(Scramble::configure(), 'resolveOperationMethodsUsing')) {
            $this->markTestSkipped('This Scramble version documents one operation per route.');
        }

        $result = app(Generator::class)();
        $paths = (is_array($result) ? $result : $result->toArray())['paths'];

        $this->assertSame('user.update', $paths['/users/{id}']['put']['operationId']);
        $this->assertSame('user.update.patch', $paths['/users/{id}']['patch']['operationId']);
        $this->assertSame('user.show', $paths['/users/{id}']['get']['operationId']);
    }
}
