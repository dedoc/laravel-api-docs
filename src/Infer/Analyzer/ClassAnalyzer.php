<?php

namespace Dedoc\Scramble\Infer\Analyzer;

use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Definition\ClassPropertyDefinition;
use Dedoc\Scramble\Infer\Definition\FunctionLikeDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\ClassDefinitionCreatedEvent;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\UnknownType;
use Illuminate\Support\Str;
use ReflectionClass;

class ClassAnalyzer
{
    public function __construct(private Index $index) {}

    private function shouldAnalyzeParentClass(ReflectionClass $parentClassReflection): bool
    {
        if ($this->index->getClassDefinition($parentClassReflection->name)) {
            return true;
        }

        /*
         * Classes from `vendor` aren't analyzed at the moment. Instead, it is up to developers to provide
         * definitions for them using the dictionaries.
         */
        return ! str_contains($parentClassReflection->getFileName(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    /**
     * @throws \ReflectionException
     */
    public function analyze(string $name): ClassDefinition
    {
        if ($definition = $this->index->getClassDefinition($name)) {
            return $definition;
        }

        $classReflection = new ReflectionClass($name);

        $parentDefinition = null;

        if ($classReflection->getParentClass() && $this->shouldAnalyzeParentClass($classReflection->getParentClass())) {
            $parentDefinition = $this->analyze($parentName = $classReflection->getParentClass()->name);
        } elseif ($classReflection->getParentClass() && ! $this->shouldAnalyzeParentClass($classReflection->getParentClass())) {
            // @todo: Here we still want to fire the event, so we can add some details to the definition.
            $parentDefinition = new ClassDefinition($parentName = $classReflection->getParentClass()->name);

            Context::getInstance()->extensionsBroker->afterClassDefinitionCreated(new ClassDefinitionCreatedEvent($parentDefinition->name, $parentDefinition));

            // In case parent definition is added in an extension.
            $parentDefinition = $this->index->getClassDefinition($parentName) ?: $parentDefinition;
        }

        /*
         * @todo consider more advanced cloning implementation.
         * Currently just cloning property definition feels alright as only its `defaultType` may change.
         */
        $classDefinition = new ClassDefinition(
            name: $name,
            templateTypes: $parentDefinition?->templateTypes ?: [],
            properties: array_map(fn ($pd) => clone $pd, $parentDefinition?->properties ?: []),
            methods: $parentDefinition?->methods ?: [],
            parentFqn: $parentName ?? null,
        );

        /*
         * Traits get analyzed by embracing default behavior of PHP reflection: reflection properties and
         * reflection methods get copied into the class that uses the trait.
         */

        foreach ($classReflection->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->class !== $name) {
                continue;
            }

            if ($reflectionProperty->isStatic()) {
                $classDefinition->properties[$reflectionProperty->name] = new ClassPropertyDefinition(
                    type: $reflectionProperty->hasDefaultValue()
                        ? (TypeHelper::createTypeFromValue($reflectionProperty->getDefaultValue()) ?: new UnknownType)
                        : new UnknownType,
                );
            } else {
                $classDefinition->properties[$reflectionProperty->name] = new ClassPropertyDefinition(
                    type: $t = new TemplateType('T'.Str::studly($reflectionProperty->name)),
                    defaultType: $reflectionProperty->hasDefaultValue()
                        ? TypeHelper::createTypeFromValue($reflectionProperty->getDefaultValue())
                        : null,
                );
                $classDefinition->templateTypes[] = $t;
            }
        }

        foreach ($classReflection->getMethods() as $reflectionMethod) {
            if ($reflectionMethod->class !== $name) {
                continue;
            }

            $classDefinition->methods[$reflectionMethod->name] = new FunctionLikeDefinition(
                new FunctionType(
                    $reflectionMethod->name,
                    arguments: [],
                    returnType: new UnknownType,
                ),
                definingClassName: $name,
                isStatic: $reflectionMethod->isStatic(),
            );
        }

        $this->index->registerClassDefinition($classDefinition);

        Context::getInstance()->extensionsBroker->afterClassDefinitionCreated(new ClassDefinitionCreatedEvent($classDefinition->name, $classDefinition));

        return $classDefinition;
    }
}
