<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Data\Parameter;
use Dedoc\Scramble\Data\ParameterMeta;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;

class RulesToParameter
{
    const RULES_PRIORITY = [
        'bool', 'boolean', 'numeric', 'int', 'integer', 'file', 'image', 'string', 'array', 'exists',
    ];

    private array $rules;

    public function __construct(
        private string $name,
        $rules,
        private ?PhpDocNode $docNode,
        private TypeTransformer $openApiTransformer,
    ) {
        $this->rules = Arr::wrap(is_string($rules) ? explode('|', $rules) : $rules);
    }

    public function generate()
    {
        if (count($this->docNode?->getTagsByName('@ignoreParam') ?? [])) {
            return null;
        }

        $rules = collect($this->rules)
            ->map(fn ($v) => method_exists($v, '__toString') ? $v->__toString() : $v)
            ->sortByDesc($this->rulesSorter());

        /** @var OpenApiType $type */
        $schema = $rules->reduce(function (OpenApiType $type, $rule) {
            if (is_string($rule)) {
                return $this->getTypeFromStringRule($type, $rule);
            }

            return method_exists($rule, 'docs')
                ? $rule->docs($type, $this->openApiTransformer)
                : $this->getTypeFromObjectRule($type, $rule);
        }, new UnknownType);

        return new Parameter(
            name: $this->name,
            description: $schema->description,
            required: $rules->contains('required') && $rules->doesntContain('sometimes'),
            schema: $schema,
            meta: ParameterMeta::applyDataFromPhpDoc(new ParameterMeta, $this->docNode, $this->openApiTransformer, preferString: $schema instanceof StringType),
        );
    }

    private function rulesSorter()
    {
        return function ($v) {
            if (! is_string($v)) {
                return -2;
            }

            $index = array_search($v, static::RULES_PRIORITY);

            return $index === false ? -1 : count(static::RULES_PRIORITY) - $index;
        };
    }

    private function getTypeFromStringRule(OpenApiType $type, string $rule)
    {
        $rulesHandler = new RulesMapper($this->openApiTransformer);

        $explodedRule = explode(':', $rule, 2);

        $ruleName = $explodedRule[0];
        $params = isset($explodedRule[1]) ? explode(',', $explodedRule[1]) : [];

        return method_exists($rulesHandler, $ruleName)
            ? $rulesHandler->$ruleName($type, $params)
            : $type;
    }

    private function getTypeFromObjectRule(OpenApiType $type, $rule)
    {
        $rulesHandler = new RulesMapper($this->openApiTransformer);

        $methodName = Str::camel(class_basename(get_class($rule)));

        return method_exists($rulesHandler, $methodName)
            ? $rulesHandler->$methodName($type, $rule)
            : $type;
    }
}
