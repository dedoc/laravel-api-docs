<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Data\Parameter;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as ArraySchema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as ObjectSchema;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DeepParametersMerger
{
    public function __construct(private Collection $parameters) {}

    public function handle()
    {
        return $this->handleNested($this->parameters->keyBy('name'))
            ->values()
            ->all();
    }

    private function handleNested(Collection $parameters)
    {
        [$forcedFlatParameters, $maybeDeepParameters] = $parameters->partition(fn (Parameter $p) => isset($p->meta->isFlat) && $p->meta->isFlat);

        [$nested, $parameters] = $maybeDeepParameters
            ->sortBy(fn ($_, $key) => count(explode('.', $key)))
            ->partition(fn ($_, $key) => Str::contains($key, '.'));

        $nestedParentsKeys = $nested->keys()->map(fn ($key) => explode('.', $key)[0]);

        [$nestedParents, $parameters] = $parameters->partition(fn ($_, $key) => $nestedParentsKeys->contains($key));

        /** @var Collection $nested */
        $nested = $nested->merge($nestedParents);

        $nested = $nested
            ->groupBy(fn ($_, $key) => explode('.', $key)[0])
            ->map(function (Collection $params, $groupName) {
                $params = $params->keyBy('name');

                $baseParam = $params->get(
                    $groupName,
                    new Parameter(
                        name: $groupName,
                        in: $params->first()->in,
                        schema: $params->keys()->contains(fn ($k) => Str::contains($k, "$groupName.*"))
                            ? new ArraySchema
                            : new ObjectSchema
                    )
                );

                $params->offsetUnset($groupName);

                foreach ($params as $param) {
                    $this->setDeepType(
                        $baseParam->schema,
                        $param->name,
                        $this->extractTypeFromParameter($param),
                    );
                }

                return $baseParam;
            });

        return $parameters
            ->merge($forcedFlatParameters)
            ->merge($nested);
    }

    private function setDeepType(Type &$base, string $key, Type $typeToSet)
    {
        $containingType = $this->getOrCreateDeepTypeContainer(
            $base,
            collect(explode('.', $key))->splice(1)->values()->all(),
        );

        if (! $containingType) {
            return;
        }

        $isSettingArrayItems = ($settingKey = collect(explode('.', $key))->last()) === '*';

        if ($containingType === $base && $base instanceof UnknownType) {
            $containingType = ($isSettingArrayItems ? new ArraySchema : new ObjectSchema)
                ->addProperties($base);

            $base = $containingType;
        }

        if (! ($containingType instanceof ArraySchema || $containingType instanceof ObjectSchema)) {
            return;
        }

        if ($isSettingArrayItems && $containingType instanceof ArraySchema) {
            $containingType->items = $typeToSet;

            return;
        }

        if (! $isSettingArrayItems && $containingType instanceof ObjectSchema) {
            $containingType
                ->addProperty($settingKey, $typeToSet)
                ->addRequired($typeToSet->getAttribute('required') ? [$settingKey] : []);
        }
    }

    private function getOrCreateDeepTypeContainer(Type &$base, array $path)
    {
        $key = $path[0];

        if (count($path) === 1) {
            if ($key !== '*' && $base instanceof ArraySchema) {
                $base = new ObjectSchema;
            }

            return $base;
        }

        if ($key === '*') {
            if (! $base instanceof ArraySchema) {
                $base = new ArraySchema;
            }

            $next = $path[1];
            if ($next === '*') {
                if (! $base->items instanceof ArraySchema) {
                    $base->items = new ArraySchema;
                }
            } else {
                if (! $base->items instanceof ObjectSchema) {
                    $base->items = new ObjectSchema;
                }
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->items,
                collect($path)->splice(1)->values()->all(),
            );
        } else {
            if (! $base instanceof ObjectSchema) {
                $base = new ObjectSchema;
            }

            $next = $path[1];

            if (! $base->hasProperty($key)) {
                $base = $base->addProperty(
                    $key,
                    $next === '*' ? new ArraySchema : new ObjectSchema,
                );
            }
            if (($existingType = $base->getProperty($key)) instanceof UnknownType) {
                $base = $base->addProperty(
                    $key,
                    ($next === '*' ? new ArraySchema : new ObjectSchema)->addProperties($existingType),
                );
            }

            if ($next === '*' && ! $existingType instanceof ArraySchema) {
                $base->addProperty($key, (new ArraySchema)->addProperties($existingType));
            }
            if ($next !== '*' && $existingType instanceof ArraySchema) {
                $base->addProperty($key, (new ObjectSchema)->addProperties($existingType));
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->properties[$key],
                collect($path)->splice(1)->values()->all(),
            );
        }
    }

    private function extractTypeFromParameter(Parameter $parameter)
    {
        $paramType = $parameter->schema;

        $paramType->setDescription($parameter->description);
        $paramType->example($parameter->example);

        return $paramType;
    }
}
