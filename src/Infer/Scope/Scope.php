<?php

namespace Dedoc\Scramble\Infer\Scope;

use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\ExtensionsBroker;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Infer\SimpleTypeGetters\BooleanNotTypeGetter;
use Dedoc\Scramble\Infer\SimpleTypeGetters\CastTypeGetter;
use Dedoc\Scramble\Infer\SimpleTypeGetters\ClassConstFetchTypeGetter;
use Dedoc\Scramble\Infer\SimpleTypeGetters\ConstFetchTypeGetter;
use Dedoc\Scramble\Infer\SimpleTypeGetters\ScalarTypeGetter;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\CallableStringType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Reference\CallableCallReferenceType;
use Dedoc\Scramble\Support\Type\Reference\MethodCallReferenceType;
use Dedoc\Scramble\Support\Type\Reference\NewCallReferenceType;
use Dedoc\Scramble\Support\Type\Reference\PropertyFetchReferenceType;
use Dedoc\Scramble\Support\Type\Reference\StaticMethodCallReferenceType;
use Dedoc\Scramble\Support\Type\SelfType;
use Dedoc\Scramble\Support\Type\SideEffects\ParentConstructCall;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Dedoc\Scramble\Support\Type\UnknownType;
use PhpParser\Node;

class Scope
{
    /**
     * @var array<string, array{line: int, type: Type}[]>
     */
    public array $variables = [];

    public function __construct(
        public Index $index,
        public NodeTypesResolver $nodeTypesResolver,
        public ScopeContext $context,
        public FileNameResolver $nameResolver,
        public ?Scope $parentScope = null,
    ) {}

    public function getType(Node $node): Type
    {
        if ($node instanceof Node\Scalar) {
            return (new ScalarTypeGetter)($node);
        }

        if ($node instanceof Node\Expr\Cast) {
            return (new CastTypeGetter)($node);
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return (new ConstFetchTypeGetter)($node);
        }

        if ($node instanceof Node\Expr\Ternary) {
            return Union::wrap([
                $this->getType($node->if ?? $node->cond),
                $this->getType($node->else),
            ]);
        }

        if ($node instanceof Node\Expr\Match_) {
            return Union::wrap(array_map(fn (Node\MatchArm $arm) => $this->getType($arm->body), $node->arms));
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            return (new ClassConstFetchTypeGetter)($node, $this);
        }

        if ($node instanceof Node\Expr\BooleanNot) {
            return (new BooleanNotTypeGetter)($node);
        }

        if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
            return new SelfType($this->classDefinition()?->name ?: 'unknown');
        }

        if ($node instanceof Node\Expr\Variable) {
            return $this->getVariableType($node) ?: new UnknownType;
        }

        $type = $this->nodeTypesResolver->getType($node);

        if (! $type instanceof UnknownType) {
            return $type;
        }

        if ($this->nodeTypesResolver->hasType($node)) { // For case when the unknown type was in node type resolver.
            return $type;
        }

        if ($node instanceof Node\Expr\New_) {
            if (! $node->class instanceof Node\Name) {
                return $type;
            }

            return $this->setType(
                $node,
                new NewCallReferenceType($node->class->toString(), $this->getArgsTypes($node->args)),
            );
        }

        if ($node instanceof Node\Expr\MethodCall) {
            // Only string method names support.
            if (! $node->name instanceof Node\Identifier) {
                return $type;
            }

            $calleeType = $this->getType($node->var);

            $event = $calleeType instanceof ObjectType
                ? new MethodCallEvent($calleeType, $node->name->name, $this, $this->getArgsTypes($node->args), $calleeType->name)
                : null;

            $exceptions = $event ? app(ExtensionsBroker::class)->getMethodCallExceptions($event) : [];

            if (
                $calleeType instanceof TemplateType
                && ! $exceptions
            ) {
                // @todo
                // if ($calleeType->is instanceof ObjectType) {
                //     $calleeType = $calleeType->is;
                // }
                return $this->setType($node, new UnknownType("Cannot infer type of method [{$node->name->name}] call on template type: not supported yet."));
            }

            $referenceType = new MethodCallReferenceType($calleeType, $node->name->name, $this->getArgsTypes($node->args));

            /*
             * When inside a constructor, we want to add a side effect to the constructor definition, so we can track
             * how the properties are being set.
             */
            if ($this->functionDefinition()?->type->name === '__construct') {
                $this->functionDefinition()->sideEffects[] = $referenceType;
            }

            if ($this->functionDefinition()) {
                $this->functionDefinition()->type->exceptions = array_merge(
                    $this->functionDefinition()->type->exceptions,
                    $exceptions,
                );
            }

            return $this->setType($node, $referenceType);
        }

        if ($node instanceof Node\Expr\StaticCall) {
            // Only string method names support.
            if (! $node->name instanceof Node\Identifier) {
                return $type;
            }

            // Only string class names support.
            if (! $node->class instanceof Node\Name) {
                return $type;
            }

            if (
                $this->functionDefinition()?->type->name === '__construct'
                && $node->class->toString() === 'parent'
                && $node->name->toString() === '__construct'
            ) {
                $this->functionDefinition()->sideEffects[] = new ParentConstructCall($this->getArgsTypes($node->args));
            }

            return $this->setType(
                $node,
                new StaticMethodCallReferenceType($node->class->toString(), $node->name->name, $this->getArgsTypes($node->args)),
            );
        }

        if ($node instanceof Node\Expr\PropertyFetch) {
            // Only string prop names support.
            if (! $name = ($node->name->name ?? null)) {
                return new UnknownType('Cannot infer type of property fetch: not supported yet.');
            }

            $calleeType = $this->getType($node->var);
            if ($calleeType instanceof TemplateType) {
                // @todo
                // if ($calleeType->is instanceof ObjectType) {
                //     $calleeType = $calleeType->is;
                // }
                return $this->setType($node, new UnknownType("Cannot infer type of property [{$name}] fetch on template type: not supported yet."));
            }

            return $this->setType(
                $node,
                new PropertyFetchReferenceType($this->getType($node->var), $name),
            );
        }

        if ($node instanceof Node\Expr\FuncCall) {
            if ($node->name instanceof Node\Name) {
                return $this->setType(
                    $node,
                    new CallableCallReferenceType(new CallableStringType($node->name->toString()), $this->getArgsTypes($node->args)),
                );
            }

            return $this->setType(
                $node,
                new CallableCallReferenceType($this->getType($node->name), $this->getArgsTypes($node->args)),
            );
        }

        return $type;
    }

    // @todo: Move to some helper, Scope should be passed as a dependency.
    public function getArgsTypes(array $args)
    {
        return collect($args)
            ->filter(fn ($arg) => $arg instanceof Node\Arg)
            ->mapWithKeys(function (Node\Arg $arg, $index) {
                $type = $this->getType($arg->value);
                if ($parsedPhpDoc = $arg->getAttribute('parsedPhpDoc')) {
                    $type->setAttribute('docNode', $parsedPhpDoc);
                }

                if (! $arg->unpack) {
                    return [$arg->name ? $arg->name->name : $index => $type];
                }

                if (! $type instanceof ArrayType && ! $type instanceof KeyedArrayType) {
                    return [$arg->name ? $arg->name->name : $index => $type]; // falling back, but not sure if we should. Maybe some DTO is needed to represent unpacked arg type?
                }

                if ($type instanceof ArrayType) {
                    /*
                     * For example, when passing something that is array, but shape is unknown
                     * $a = foo(...array_keys($bar));
                     */
                    return [$arg->name ? $arg->name->name : $index => $type]; // falling back, but not sure if we should. Maybe some DTO is needed to represent unpacked arg type?
                }

                return collect($type->items)
                    ->mapWithKeys(fn (ArrayItemType_ $item, $i) => [
                        $item->key ?: $index + $i => $item->value,
                    ])
                    ->all();
            })
            ->toArray();
    }

    public function setType(Node $node, Type $type)
    {
        $this->nodeTypesResolver->setType($node, $type);

        return $type;
    }

    public function createChildScope(?ScopeContext $context = null)
    {
        return new Scope(
            $this->index,
            $this->nodeTypesResolver,
            $context ?: $this->context,
            $this->nameResolver,
            $this,
        );
    }

    public function getContextTemplates()
    {
        return [
            ...($this->classDefinition()?->templateTypes ?: []),
            ...($this->functionDefinition()?->type->templates ?: []),
            ...($this->parentScope?->getContextTemplates() ?: []),
        ];
    }

    public function makeConflictFreeTemplateName(string $name): string
    {
        $scopeDuplicateTemplates = collect($this->getContextTemplates())
            ->pluck('name')
            ->unique()
            ->values()
            ->filter(fn ($n) => preg_match('/^'.$name.'(\d*)?$/m', $n) === 1)
            ->all();

        return $name.($scopeDuplicateTemplates ? count($scopeDuplicateTemplates) : '');
    }

    public function isInClass()
    {
        return (bool) $this->context->classDefinition;
    }

    public function classDefinition(): ?ClassDefinition
    {
        return $this->context->classDefinition;
    }

    public function functionDefinition()
    {
        return $this->context->functionDefinition;
    }

    public function isInFunction()
    {
        return (bool) $this->context->functionDefinition;
    }

    public function addVariableType(int $line, string $name, Type $type)
    {
        if (! isset($this->variables[$name])) {
            $this->variables[$name] = [];
        }

        $this->variables[$name][] = compact('line', 'type');
    }

    private function getVariableType(Node\Expr\Variable $node)
    {
        $name = (string) $node->name;
        $line = $node->getAttribute('startLine', 0);

        $definitions = $this->variables[$name] ?? [];

        $type = new UnknownType;
        foreach ($definitions as $definition) {
            if ($definition['line'] > $line) {
                return $type;
            }
            $type = $definition['type'];
        }

        return $type;
    }
}
