<?php

namespace Dedoc\Scramble\Infer\Services;

use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\EnumCaseType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\UnknownType;

class ConstFetchTypeGetter
{
    public function __invoke(Scope $scope, string $className, string $constName)
    {
        if ($constName === 'class') {
            return new LiteralStringType($className);
        }

        try {
            $constantReflection = new \ReflectionClassConstant($className, $constName);
            $constantValue = $constantReflection->getValue();

            if ($constantReflection->isEnumCase()) {
                return new EnumCaseType($className, $constName);
            }

            $type = TypeHelper::createTypeFromValue($constantValue);

            if ($type) {
                return $type;
            }
        } catch (\ReflectionException $e) {
            return new UnknownType('Cannot get const value');
        }

        return new UnknownType('ConstFetchTypeGetter is not yet implemented fully for non-class const fetches.');
    }
}
