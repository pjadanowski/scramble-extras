<?php

namespace PawelJadanowski\ScrambleExtras;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Contracts\BaseData;

class LaravelDataParametersExtractor implements ParameterExtractor
{
    /**
     * Same list as Scramble's RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY.
     */
    private const METHODS_WITHOUT_BODY = ['get', 'delete', 'head'];

    public function __construct(
        protected TypeTransformer $openApiTransformer,
    ) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        $reflectionMethod = $routeInfo->reflectionMethod();
        if (! $reflectionMethod) {
            return $parameterExtractionResults;
        }

        foreach ($reflectionMethod->getParameters() as $parameter) {
            $dataClass = $this->getDataClassName($parameter);
            if ($dataClass === null) {
                continue;
            }

            $parameterExtractionResults[] = $this->buildResult($dataClass, $routeInfo);
        }

        return $parameterExtractionResults;
    }

    /**
     * RouteInfo::$method only exists since Scramble documents one operation
     * per HTTP method; before that, the operation was the route's first one.
     */
    protected function httpMethod(RouteInfo $routeInfo): string
    {
        return property_exists($routeInfo, 'method')
            ? $routeInfo->method
            : ($routeInfo->route->methods()[0] ?? 'GET');
    }

    protected function getDataClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        $className = $type->getName();
        if (! class_exists($className)) {
            return null;
        }

        if (! is_a($className, BaseData::class, true)) {
            return null;
        }

        return (new ReflectionClass($className))->isInstantiable()
            ? $className
            : null;
    }

    protected function buildResult(string $dataClass, RouteInfo $routeInfo): ParametersExtractionResult
    {
        $reflector = new LaravelDataReflector(
            $this->openApiTransformer,
            $this->openApiTransformer->getComponents(),
        );

        $schema = $reflector->buildSchema($dataClass, input: true);
        (new DataRulesSchemaMerger($this->openApiTransformer))->merge($schema, $dataClass, $routeInfo);
        $httpMethod = $this->httpMethod($routeInfo);

        // Without a request body, laravel-data fills the Data object from the
        // query string, so each property is its own query parameter.
        if (in_array(strtolower($httpMethod), self::METHODS_WITHOUT_BODY, true)) {
            return new ParametersExtractionResult(
                parameters: $this->queryParameters($schema),
                schemaName: null,
            );
        }

        $body = (new Parameter('*', 'body'))
            ->setSchema(Schema::fromType($schema));

        // The body schema is inlined (no `schemaName`) on purpose. A Data class
        // is frequently used as both a response and a request body, and its
        // input and output projections differ: `#[Computed]` props are output
        // only, `#[Hidden]` props are input only, `Optional`/`MapInputName`
        // change required-ness and naming. If we registered the input schema
        // under the bare class name, it would collide with the output component
        // of the same name — and whichever was generated last would silently
        // win, leaving the request body describing the wrong shape. Inlining
        // keeps the request body faithful to the input rules regardless.
        return new ParametersExtractionResult(
            parameters: [$body],
            schemaName: null,
        );
    }

    /**
     * @return Parameter[]
     */
    protected function queryParameters(OpenApiObjectType $schema): array
    {
        $parameters = [];

        foreach ($schema->properties as $name => $type) {
            $parameter = (new Parameter($name, 'query'))
                ->required(in_array($name, $schema->required, true))
                ->setSchema(Schema::fromType($type));

            if ($type->description !== '') {
                $parameter->description($type->description);
                $type->setDescription('');
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }
}
