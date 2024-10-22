<?php

namespace Dedoc\Scramble\Support\Type;

class FunctionType extends AbstractType implements FunctionLikeType
{
    public string $name;

    /**
     * @var array<string, Type> Key is an argument name, and value is its type.
     */
    public array $arguments;

    public Type $returnType;

    public array $templates = [];

    /**
     * @var array<ObjectType|Generic>
     */
    public array $exceptions;

    public function __construct(
        string $name,
        $arguments = [],
        $returnType = null,
        $exceptions = []
    ) {
        $this->name = $name;
        $this->arguments = $arguments;
        $this->returnType = $returnType ?: new VoidType;
        $this->exceptions = $exceptions;
    }

    public function nodes(): array
    {
        return ['returnType', 'arguments'];
    }

    public function setReturnType(Type $type): self
    {
        $this->returnType = $type;

        return $this;
    }

    public function getReturnType(): Type
    {
        return $this->returnType;
    }

    public function isSame(Type $type)
    {
        return $type instanceof static
            && $this->returnType->isSame($type->returnType)
            && collect($this->arguments)->every(fn (Type $t, $i) => $t->isSame($type->arguments[$i]));
    }

    public function toString(): string
    {
        return sprintf(
            '%s(%s): %s',
            $this->templates ? sprintf('<%s>', implode(', ', array_map(fn ($t) => $t->toDefinitionString(), $this->templates))) : '',
            implode(', ', array_map(fn ($t) => $t->toString(), $this->arguments)),
            $this->returnType->toString(),
        );
    }
}
