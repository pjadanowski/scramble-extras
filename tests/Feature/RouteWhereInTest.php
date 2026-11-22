<?php

namespace PawelJadanowski\ScrambleExtras\Tests\Feature;

use Dedoc\Scramble\Generator;
use Illuminate\Routing\Router;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\RouteConstraintsController as C;
use PawelJadanowski\ScrambleExtras\Tests\Fixtures\StatusEnum;
use PawelJadanowski\ScrambleExtras\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * whereIn() route constraints narrow the matching path parameter to an enum.
 * Ported from HishamM1/scramble-extras (b7f673c).
 */
class RouteWhereInTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->pattern('locale', 'en|ar');
        $router->get('api/where-in/{kind}', [C::class, 'kind'])->whereIn('kind', ['alpha', 'beta_two']);
        $router->get('api/where-in-enum/{kind}', [C::class, 'kind'])->whereIn('kind', [StatusEnum::Active, StatusEnum::Pending]);
        $router->get('api/where-regex/{id}', [C::class, 'id'])->where('id', '[0-9]+');
        $router->get('api/where-in-ref/{status}', [C::class, 'byStatus'])->whereIn('status', ['active', 'pending']);
        $router->get('api/where-in-two/{org}/{kind}', [C::class, 'twoParams'])->whereIn('kind', ['alpha', 'beta']);
        $router->get('api/where-in-renamed/{slug}', [C::class, 'kind'])->whereIn('slug', ['alpha', 'beta']);
        $router->get('api/where-in-int/{id}', [C::class, 'id'])->whereIn('id', [1, 2]);
        $router->get('api/where-in-default/{kind?}', [C::class, 'withDefault'])->whereIn('kind', ['alpha', 'beta']);
        $router->get('api/where-in-attr/{org}/{kind}', [C::class, 'attributedParams'])->whereIn('kind', ['alpha', 'beta']);
        $router->domain('{tenant}.example.com')->get('api/where-in-domain/{kind}', [C::class, 'kind'])->whereIn('kind', ['alpha', 'beta']);
        $router->get('api/where-in-int-lead/{id}', [C::class, 'id'])->whereIn('id', ['007', '010']);
        $router->get('api/where-in-int-ok/{id}', [C::class, 'id'])->whereIn('id', ['1', '2']);
        $router->get('api/where-in-dotted/{kind}', [C::class, 'kind'])->whereIn('kind', ['v1.0', 'v2.0']);
        $router->get('api/locale-pattern/{lang}', [C::class, 'byLocale']);
        $router->get('api/ref-plain/{status}', [C::class, 'byStatus']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pathParameterSchema(string $path, string $name): array
    {
        $result = app(Generator::class)();
        $document = is_array($result) ? $result : $result->toArray();

        foreach ($document['paths'][$path]['get']['parameters'] as $parameter) {
            if ($parameter['in'] === 'path' && $parameter['name'] === $name) {
                return $parameter['schema'];
            }
        }

        $this->fail("Path parameter {$name} not found on {$path}");
    }

    #[Test]
    public function where_in_constraints_become_path_parameter_enums(): void
    {
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta_two']], $this->pathParameterSchema('/where-in/{kind}', 'kind'));
        $this->assertSame(['type' => 'string', 'enum' => ['active', 'pending']], $this->pathParameterSchema('/where-in-enum/{kind}', 'kind'));
        $this->assertSame(['type' => 'string', 'enum' => ['active', 'pending']], $this->pathParameterSchema('/where-in-ref/{status}', 'status'));
    }

    #[Test]
    public function other_patterns_and_unconstrained_parameters_are_left_alone(): void
    {
        $this->assertSame(['$ref' => '#/components/schemas/StatusEnum'], $this->pathParameterSchema('/ref-plain/{status}', 'status'));
        $this->assertSame(['type' => 'integer'], $this->pathParameterSchema('/where-regex/{id}', 'id'));
        $this->assertSame(['type' => 'string'], $this->pathParameterSchema('/where-in-dotted/{kind}', 'kind'));
    }

    #[Test]
    public function only_the_matching_path_parameter_is_narrowed(): void
    {
        $this->assertSame(['type' => 'string'], $this->pathParameterSchema('/where-in-two/{org}/{kind}', 'org'));
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta']], $this->pathParameterSchema('/where-in-two/{org}/{kind}', 'kind'));
    }

    #[Test]
    public function the_scramble_parameter_name_is_used_when_the_argument_name_differs(): void
    {
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta']], $this->pathParameterSchema('/where-in-renamed/{kind}', 'kind'));
    }

    #[Test]
    public function global_patterns_for_names_not_in_the_uri_are_ignored(): void
    {
        $this->assertSame(['type' => 'string'], $this->pathParameterSchema('/locale-pattern/{locale}', 'locale'));
    }

    #[Test]
    public function path_parameter_attributes_and_domain_parameters_do_not_interfere(): void
    {
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta']], $this->pathParameterSchema('/where-in-attr/{org}/{kind}', 'kind'));
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta']], $this->pathParameterSchema('/where-in-domain/{kind}', 'kind'));
    }

    #[Test]
    public function integer_parameters_keep_their_type_and_only_take_canonical_integers(): void
    {
        $this->assertSame(['type' => 'integer', 'enum' => [1, 2]], $this->pathParameterSchema('/where-in-int/{id}', 'id'));
        $this->assertSame(['type' => 'integer', 'enum' => [1, 2]], $this->pathParameterSchema('/where-in-int-ok/{id}', 'id'));
        $this->assertSame(['type' => 'integer'], $this->pathParameterSchema('/where-in-int-lead/{id}', 'id'));
    }

    #[Test]
    public function the_parameter_default_is_preserved(): void
    {
        $this->assertSame(['type' => 'string', 'enum' => ['alpha', 'beta'], 'default' => 'beta'], $this->pathParameterSchema('/where-in-default/{kind}', 'kind'));
    }
}
