<?php

namespace Dedoc\Scramble\Infer\Visitors;

use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Definition\ClassPropertyDefinition;
use Dedoc\Scramble\Infer\Definition\FunctionLikeDefinition;
use Dedoc\Scramble\Infer\Scope\Index;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Scope\ScopeContext;
use Dedoc\Scramble\Infer\Services\Symbol;
use Dedoc\Scramble\PhpDoc\PhpDocTypeHelper;
use Dedoc\Scramble\Support\PhpDoc;
use Dedoc\Scramble\Support\Type\FunctionType;
use Dedoc\Scramble\Support\Type\MixedType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeHelper;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\VoidType;
use Illuminate\Support\Str;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use function DeepCopy\deep_copy;

/**
 * Shallow class analyzing does not do any assumptions about templates and does not try to infer them. It simply
 * creates classes definitions based on method signatures and PHPDoc comments.
 */
class ShallowClassAnalyzingVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private Index $index,
        private Scope $scope,
        private Symbol $symbol,
        private ScopeContext $context = new ScopeContext,
    ) {}

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Function_) {
            if (! $this->symbol->filterNodes($node)) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            if (! $this->symbol->filterNodes($node)) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            $this->index->registerClassDefinition($classDefinition = $this->buildClassDefinitionFromClassNode($node));
            $this->context = new ScopeContext($classDefinition);

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $this->addPropertyDefinitionFromNode($this->context->classDefinition, $node);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($node->name->toString() === '__construct') {
                $this->addPromotedProperties($this->context->classDefinition, $node);
            }

            $this->addMethodDefinitionFromNode($this->context->classDefinition, $node);

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->context = new ScopeContext;
        }

        return parent::leaveNode($node);
    }

    private function addMethodDefinitionFromNode(ClassDefinition $classDefinition, Node\Stmt\ClassMethod $node)
    {
        $name = $node->name->toString();

        $classDefinition->methods[$name] = $methodDefinition = new FunctionLikeDefinition(
            type: new FunctionType(
                name: $name,
                arguments: collect($node->params)->mapWithKeys(function (Node\Param $p) {
                    return [
                        (string) $p->var->name => $p->type ? TypeHelper::createTypeFromTypeNode($p->type) : new MixedType,
                    ];
                })->toArray(),
                returnType: $node->returnType ? TypeHelper::createTypeFromTypeNode($node->returnType) : new VoidType,
                exceptions: [],
            ),
            sideEffects: [],
            argumentsDefaults: collect($node->params)->filter(fn (Node\Param $p) => $p->default)->mapWithKeys(function (Node\Param $p) {
                return [
                    (string) $p->var->name => $this->scope->getType($p->default),
                ];
            })->toArray(),
            definingClassName: $classDefinition->name,
        );

        $methodDefinition->isFullyAnalyzed = true;

        $this->applyMethodAnnotations($classDefinition, $methodDefinition, $node);
    }

    private function applyMethodAnnotations(ClassDefinition $classDefinition, FunctionLikeDefinition $methodDefinition, Node\Stmt\ClassMethod $node)
    {
        $doc = $node->getDocComment();

        if (! $doc) {
            return;
        }

        $phpDoc = PhpDoc::parse($doc->getText());

        foreach ($this->getAllTemplateDefinitions($phpDoc) as $templateTagValue) {
            $methodDefinition->type->templates[] = new TemplateType(
                $templateTagValue->name,
                is: $templateTagValue->bound ? PhpDocTypeHelper::toType($templateTagValue->bound) : null,
            );
        }

        foreach ($phpDoc->getParamTagValues() as $paramTagValue) {
            $name = Str::replace('$', '', $paramTagValue->parameterName);
            if (array_key_exists($name, $methodDefinition->type->arguments)) {
                $methodDefinition->type->arguments[$name] = $this->replaceTemplateTypes(
                    PhpDocTypeHelper::toType($paramTagValue->type),
                    $classDefinition,
                    $methodDefinition,
                );
            } else {
                // @todo raise error: annotation for non-existing parameter (?)
            }
        }

        if ($returnTags = $phpDoc->getReturnTagValues()) {
            $lastReturnTag = $returnTags[count($returnTags) - 1];

            $methodDefinition->type->returnType = $this->replaceTemplateTypes(
                PhpDocTypeHelper::toType($lastReturnTag->type),
                $classDefinition,
                $methodDefinition,
            );
        }
    }

    private function getAllTemplateDefinitions(PhpDocNode $phpDoc): array
    {
        return [
            ...$phpDoc->getTemplateTagValues(),
            ...$phpDoc->getTemplateTagValues('@template-covariant'),
        ];
    }

    /**
     * After parsing PHPDoc type like `TFoo`, the resulting type is ObjectType with name 'TFoo'. But we'd want to keep a
     * reference to the template type, so we replace such objects with templates types defined in classes and methods.
     */
    private function replaceTemplateTypes(Type $subject, ClassDefinition $classDefinition, ?FunctionLikeDefinition $methodDefinition = null): Type
    {
        $allTemplateDefinitions = collect([
            ...$classDefinition->templateTypes,
            ...($methodDefinition->type->templates ?? []),
        ])->keyBy(fn (TemplateType $templateType) => $templateType->name);

        return (new TypeWalker)->replace(
            $subject,
            fn (Type $t) => $t instanceof ObjectType && $allTemplateDefinitions->has($t->name)
                ? $allTemplateDefinitions->get($t->name)
                : null,
        );
    }

    private function addPromotedProperties(ClassDefinition $classDefinition, Node\Stmt\ClassMethod $node)
    {
        $promotedProperties = collect($node->getParams())
            ->filter(fn (Node\Param $p) => $p->isPromoted())
            ->mapWithKeys(fn (Node\Param $param) => $param->var instanceof Node\Expr\Variable ? [
                $name = $param->var->name => $this->buildPropertyDefinition(
                    $classDefinition,
                    $name,
                    $node->getDocComment(),
                    $param->type,
                    $param->default,
                    '@param',
                ),
            ] : [])
            ->toArray();

        $classDefinition->properties = array_merge($promotedProperties, $classDefinition->properties);
    }

    private function addPropertyDefinitionFromNode(ClassDefinition $classDefinition, Node\Stmt\Property $propertyNode)
    {
        foreach ($propertyNode->props as $propertyItem) {
            $name = $propertyItem->name->toString();

            $classDefinition->properties[$name] = $this->buildPropertyDefinition(
                $classDefinition,
                $name,
                $propertyNode->getDocComment(),
                $propertyNode->type,
                $propertyItem->default,
            );
        }
    }

    private function buildClassDefinitionFromClassNode(Node\Stmt\Class_ $node)
    {
        $comment = PhpDoc::parse($node->getDocComment()?->getText() ?? '/** */');
        $templates = array_map(
            fn (TemplateTagValueNode $n) => new TemplateType(
                $n->name,
                is: $n->bound ? PhpDocTypeHelper::toType($n->bound) : null,
            ),
            $this->getAllTemplateDefinitions($comment),
        );

        $parentDefinition = ($extends = $node->extends?->toString())
            ? $this->applySpecifiedTemplates($this->index->getClassDefinition($extends), $comment)
            : new ClassDefinition('null');

        return new ClassDefinition(
            name: $this->getNamespacedClassName($node),
            templateTypes: array_merge($parentDefinition->templateTypes, $templates),
            properties: $parentDefinition->properties,
            methods: $parentDefinition->methods,
            parentFqn: $node->extends?->toString(),
        );
    }

    private function getNamespacedClassName(Node\Stmt\Class_ $node)
    {
        $name = $node->namespacedName
            ? $node->namespacedName->toString()
            : $node->name->toString();

        /** @var Node\Attribute|null $nsAttribute */
        $nsAttribute = collect($node->attrGroups)
            ->flatMap->attrs
            ->first(fn (Node\Attribute $a) => $a->name->toString() === 'NS' && is_string($a->args[0]->value->value ?? null));

        $ns = $nsAttribute ? $nsAttribute->args[0]->value->value : '';

        return $ns ? $ns.'\\'.Str::afterLast($name, '\\') : $name;
    }

    /**
     * Needs some cleanup.
     */
    public function buildPropertyDefinition(ClassDefinition $classDefinition, string $name, ?Doc $doc, $type, $default, $tagLookup = '@var'): ClassPropertyDefinition
    {
        $templates = collect($classDefinition->templateTypes)->keyBy('name');

        $propertyType = $type ? TypeHelper::createTypeFromTypeNode($type) : new MixedType;

        if ($doc) {
            $parsedPhpDoc = PhpDoc::parse($doc->getText());

            /** @var VarTagValueNode|ParamTagValueNode $varDefiningNode */
            $varDefiningNode = $tagLookup === '@var'
                ? collect($parsedPhpDoc->getVarTagValues())->first()
                : collect($parsedPhpDoc->getParamTagValues())->firstWhere('parameterName', '$'.$name);

            if ($varDefiningNode) {
                $propertyType = PhpDocTypeHelper::toType($varDefiningNode->type);

                if ($propertyType instanceof ObjectType && $templates->has($propertyType->name)) {
                    $propertyType = $templates->get($propertyType->name);
                }
            }
        }

        return new ClassPropertyDefinition(
            type: $propertyType,
            defaultType: $default ? $this->scope->getType($default) : null,
        );
    }

    private function applySpecifiedTemplates(ClassDefinition $parentDefinition, PhpDocNode $comment)
    {
        $parentDefinition = deep_copy($parentDefinition);

        $appliedTemplateTypes = $comment->getExtendsTagValues()[0]->type->genericTypes ?? [];
        if (! $appliedTemplateTypes) {
            return $parentDefinition;
        }

        if (count($parentDefinition->templateTypes) !== count($appliedTemplateTypes)) {
            throw new \Exception(count($parentDefinition->templateTypes)." template arguments is expected for extending ".$parentDefinition->name." class, and exactly ".count($appliedTemplateTypes)." passed");
        }

        $parentDefinitionTemplates = $parentDefinition->templateTypes;
        foreach ($parentDefinitionTemplates as $templateIndex => $template) {
            foreach ($parentDefinition->properties as $propertyDefinition) {
                $propertyDefinition->type = (new TypeWalker)->replace(
                    $propertyDefinition->type,
                    fn (Type $t) => $t === $template
                        ? PhpDocTypeHelper::toType($appliedTemplateTypes[$templateIndex])
                        : null,
                );
            }
            foreach ($parentDefinition->methods as $methodDefinition) {
                $methodDefinition->type = (new TypeWalker)->replace(
                    $methodDefinition->type,
                    fn (Type $t) => $t === $template
                        ? PhpDocTypeHelper::toType($appliedTemplateTypes[$templateIndex])
                        : null,
                );
            }
        }
        $parentDefinition->templateTypes = [];

        return $parentDefinition;
    }
}
