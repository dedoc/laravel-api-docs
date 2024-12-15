<?php

namespace Dedoc\Scramble\Infer\Extensions;

class ExtensionsBroker
{
    public function __construct(
        public readonly array $extensions = [],
    ) {}

    public function getPropertyType($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof PropertyTypeExtension
                && $e->shouldHandle($event->getInstance());
        });

        foreach ($extensions as $extension) {
            if ($propertyType = $extension->getPropertyType($event)) {
                return $propertyType;
            }
        }

        return null;
    }

    public function getMethodReturnType($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof MethodReturnTypeExtension
                && $e->shouldHandle($event->getInstance());
        });

        foreach ($extensions as $extension) {
            if ($propertyType = $extension->getMethodReturnType($event)) {
                return $propertyType;
            }
        }

        return null;
    }

    public function getMethodCallExceptions($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof MethodCallExceptionsExtension
                && $e->shouldHandle($event->getInstance());
        });

        $exceptions = [];
        foreach ($extensions as $extension) {
            if ($extensionExceptions = $extension->getMethodCallExceptions($event)) {
                $exceptions = array_merge($exceptions, $extensionExceptions);
            }
        }

        return $exceptions;
    }

    public function getStaticMethodReturnType($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof StaticMethodReturnTypeExtension
                && $e->shouldHandle($event->getCallee());
        });

        foreach ($extensions as $extension) {
            if ($propertyType = $extension->getStaticMethodReturnType($event)) {
                return $propertyType;
            }
        }

        return null;
    }

    public function getFunctionReturnType($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof FunctionReturnTypeExtension
                && $e->shouldHandle($event->getName());
        });

        foreach ($extensions as $extension) {
            if ($propertyType = $extension->getFunctionReturnType($event)) {
                return $propertyType;
            }
        }

        return null;
    }

    public function afterClassDefinitionCreated($event)
    {
        $extensions = array_filter($this->extensions, function ($e) use ($event) {
            return $e instanceof AfterClassDefinitionCreatedExtension
                && $e->shouldHandle($event->name);
        });

        foreach ($extensions as $extension) {
            $extension->afterClassDefinitionCreated($event);
        }
    }
}
