<?php

namespace Dedoc\Scramble;

use Dedoc\Scramble\Support\ComplexTypeHandler\ComplexTypeHandlers;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\ResponseExtractor\ResponsesExtractor;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\RulesExtractor\FormRequestRulesExtractor;
use Dedoc\Scramble\Support\RulesExtractor\ValidateCallExtractor;
use Dedoc\Scramble\Support\Type\Identifier;
use Dedoc\Scramble\Support\TypeHandlers\TypeHandlers;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class Generator
{
    public function __invoke()
    {
        $openApi = $this->makeOpenApi();

        ComplexTypeHandlers::registerComponentsRepository($openApi->components);

        $this->getRoutes()
            ->map(fn (Route $route) => $this->routeToOperation($openApi, $route))
            ->filter() // Closure based routes are filtered out for now
            ->eachSpread(fn (string $path, Operation $operation) => $openApi->addPath(
                Path::make(str_replace('api/', '', $path))->addOperation($operation)
            ))
            ->toArray();

        if (isset(Scramble::$openApiExtender)) {
            $openApi = (Scramble::$openApiExtender)($openApi);
        }

        return $openApi->toArray();
    }

    private function makeOpenApi()
    {
        $openApi = OpenApi::make('3.1.0')
            ->addInfo(InfoObject::make(config('app.name'))->setVersion('0.0.1'));

        $openApi->addServer(Server::make(url('/api')));

        return $openApi;
    }

    private function getRoutes(): Collection
    {
        return collect(RouteFacade::getRoutes())
            ->filter(function (Route $route) {
                return ! ($name = $route->getAction('as')) || ! Str::startsWith($name, 'api-docs');
            })
            ->filter(function (Route $route) {
                $routeResolver = Scramble::$routeResolver ?? fn (Route $route) => in_array('api', $route->gatherMiddleware());

                return $routeResolver($route);
            })
            ->values();
    }

    private function routeToOperation(OpenApi $openApi, Route $route)
    {
        $routeInfo = new RouteInfo($route);

        if (! $routeInfo->isClassBased()) {
            return null;
        }

        TypeHandlers::registerIdentifierHandler(
            $routeInfo->className(),
            function (string $name) use ($routeInfo) {
                return ComplexTypeHandlers::handle(new Identifier($routeInfo->class->resolveFqName($name)));
            },
        );

        [$pathParams, $pathAliases] = $this->getRoutePathParameters($route, $routeInfo->phpDoc());

        $operation = Operation::make($method = strtolower($route->methods()[0]))
            ->setTags(array_merge(
                $this->extractTagsForMethod($routeInfo->class->phpDoc()),
                [Str::of(class_basename($routeInfo->className()))->replace('Controller', '')],
            ))
            ->addParameters($pathParams);

        $description = Str::of($routeInfo->phpDoc()->getAttribute('description'));
        try {
            if (count($bodyParams = $this->extractParamsFromRequestValidationRules($route, $routeInfo->methodNode()))) {
                if ($method !== 'get') {
                    $operation->addRequestBodyObject(
                        RequestBodyObject::make()->setContent('application/json', Schema::createFromParameters($bodyParams))
                    );
                } else {
                    $operation->addParameters($bodyParams);
                }
            } elseif ($method !== 'get') {
                $operation
                    ->addRequestBodyObject(
                        RequestBodyObject::make()
                            ->setContent(
                                'application/json',
                                Schema::fromType(new ObjectType)
                            )
                    );
            }
        } catch (\Throwable $exception) {
            $description = $description->append('⚠️Cannot generate request documentation: '.$exception->getMessage());
        }

        $responses = (new ResponsesExtractor($openApi, $route, $routeInfo->methodNode(), $routeInfo->reflectionMethod(), $routeInfo->phpDoc(), $routeInfo->class->namesResolver))();
        foreach ($responses as $response) {
            $operation->addResponse($response);
        }

        $operation
            ->summary(Str::of($routeInfo->phpDoc()->getAttribute('summary'))->rtrim('.'))
            ->description($description);

        if (isset(Scramble::$operationResolver)) {
            (Scramble::$operationResolver)($operation, $routeInfo);
        }

        return [
            Str::replace(array_keys($pathAliases), array_values($pathAliases), $route->uri),
            $operation,
        ];
    }

    private function extractTagsForMethod(PhpDocNode $classPhpDoc)
    {
        if (! count($tagNodes = $classPhpDoc->getTagsByName('@tags'))) {
            return [];
        }

        return explode(',', $tagNodes[0]->value->value);
    }

    private function getRoutePathParameters(Route $route, ?PhpDocNode $methodPhpDocNode)
    {
        $paramNames = $route->parameterNames();
        $paramsWithRealNames = ($reflectionParams = collect($route->signatureParameters())
            ->filter(function (\ReflectionParameter $v) {
                if (($type = $v->getType()) && $typeName = $type->getName()) {
                    if (is_a($typeName, Request::class, true)) {
                        return false;
                    }
                }

                return true;
            })
            ->values())
            ->map(fn (\ReflectionParameter $v) => $v->name)
            ->all();

        if (count($paramNames) !== count($paramsWithRealNames)) {
            $paramsWithRealNames = $paramNames;
        }

        $aliases = collect($paramNames)->mapWithKeys(fn ($name, $i) => [$name => $paramsWithRealNames[$i]])->all();

        $reflectionParamsByKeys = $reflectionParams->keyBy->name;
        $phpDocTypehintParam = $methodPhpDocNode
            ? collect($methodPhpDocNode->getParamTagValues())->keyBy(fn (ParamTagValueNode $n) => Str::replace('$', '', $n->parameterName))
            : collect();

        /*
         * Figure out param type based on importance priority:
         * 1. Typehint (reflection)
         * 2. PhpDoc Typehint
         * 3. String (?)
         */
        $params = array_map(function (string $paramName) use ($aliases, $reflectionParamsByKeys, $phpDocTypehintParam) {
            $paramName = $aliases[$paramName];

            $description = '';
            $type = null;

            if (isset($reflectionParamsByKeys[$paramName]) || isset($phpDocTypehintParam[$paramName])) {
                /** @var ParamTagValueNode $docParam */
                if ($docParam = $phpDocTypehintParam[$paramName] ?? null) {
                    if ($docType = $docParam->type) {
                        $type = (string) $docType;
                    }
                    if ($docParam->description) {
                        $description = $docParam->description;
                    }
                }

                if (
                    ($reflectionParam = $reflectionParamsByKeys[$paramName] ?? null)
                    && ($reflectionParam->hasType())
                ) {
                    /** @var \ReflectionParameter $reflectionParam */
                    $type = $reflectionParam->getType()->getName();
                }
            }

            $schemaTypesMap = [
                'int' => new IntegerType(),
                'float' => new NumberType(),
                'string' => new StringType(),
                'bool' => new BooleanType(),
            ];
            $schemaType = $type ? ($schemaTypesMap[$type] ?? new IntegerType) : new StringType;

            if ($type && ! isset($schemaTypesMap[$type]) && $description === '') {
                $description = 'The '.Str::of($paramName)->kebab()->replace(['-', '_'], ' ').' ID';
            }

            return Parameter::make($paramName, 'path')
                ->description($description)
                ->setSchema(Schema::fromType($schemaType));
        }, $route->parameterNames());

        return [$params, $aliases];
    }

    private function extractParamsFromRequestValidationRules(Route $route, ?Node\Stmt\ClassMethod $methodNode)
    {
        $rules = $this->extractRouteRequestValidationRules($route, $methodNode);

        if (! $rules) {
            return [];
        }

        return collect($rules)
            ->map(function ($rules, $name) {
                $rules = Arr::wrap(is_string($rules) ? explode('|', $rules) : $rules);
                $rules = array_map(
                    fn ($v) => method_exists($v, '__toString') ? $v->__toString() : $v,
                    $rules,
                );

                $type = new StringType;
                $description = '';
                $enum = [];

                if (in_array('bool', $rules) || in_array('boolean', $rules)) {
                    $type = new BooleanType;
                } elseif (in_array('numeric', $rules)) {
                    $type = new NumberType;
                } elseif (in_array('integer', $rules) || in_array('int', $rules)) {
                    $type = new IntegerType;
                }

                if (collect($rules)->contains(fn ($v) => is_string($v) && Str::is('exists:*,id*', $v))) {
                    $type = new IntegerType;
                }

                if ($inRule = collect($rules)->first(fn ($v) => is_string($v) && Str::is('in:*', $v))) {
                    $enum = Str::of($inRule)
                        ->replaceFirst('in:', '')
                        ->explode(',')
                        ->mapInto(Stringable::class)
                        ->map(fn (Stringable $v) => (string) $v->trim('"')->replace('""', '"'))
                        ->values()->all();
                }

                if (in_array('nullable', $rules)) {
                    $type->nullable(true);
                }

                if ($type instanceof NumberType) {
                    if ($min = Str::replace('min:', '', collect($rules)->first(fn ($v) => is_string($v) && Str::startsWith($v, 'min:'), ''))) {
                        $type->setMin((float) $min);
                    }
                    if ($max = Str::replace('max:', '', collect($rules)->first(fn ($v) => is_string($v) && Str::startsWith($v, 'max:'), ''))) {
                        $type->setMax((float) $max);
                    }
                }

                return Parameter::make($name, 'query')
                    ->setSchema(Schema::fromType($type)->enum($enum))
                    ->required(in_array('required', $rules))
                    ->description($description);
            })
            ->values()
            ->all();
    }

    private function extractRouteRequestValidationRules(Route $route, $methodNode)
    {
        // Custom form request's class `validate` method
        if (($formRequestRulesExtractor = new FormRequestRulesExtractor($methodNode))->shouldHandle()) {
            return $formRequestRulesExtractor->extract($route);
        }

        if (($validateCallExtractor = new ValidateCallExtractor($methodNode))->shouldHandle()) {
            return $validateCallExtractor->extract($route);
        }

        return null;
    }
}
