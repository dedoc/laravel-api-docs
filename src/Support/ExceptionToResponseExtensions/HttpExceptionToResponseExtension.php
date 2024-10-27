<?php

namespace Dedoc\Scramble\Support\ExceptionToResponseExtensions;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HttpExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(HttpException::class);
    }

    /**
     * @param  ObjectType  $type
     */
    public function toResponse(Type $type)
    {
        $codeType = $this->getTemplateType($type, 'TStatusCode');

        if (! $codeType instanceof LiteralIntegerType) {
            return null;
        }

        $responseBodyType = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'message',
                tap((new OpenApiTypes\StringType)->setDescription('Error overview.'), function (OpenApiTypes\StringType $t) use ($type) {
                    $messageType = $this->getTemplateType($type, 'TMessage');
                    if (! $messageType instanceof LiteralStringType) {
                        return;
                    }
                    $t->example($messageType->value);
                })
            )
            ->setRequired(['message']);

        return Response::make($codeType->value)
            ->description('An error')
            ->setContent(
                'application/json',
                Schema::fromType($responseBodyType)
            );
    }

    private function getTemplateType(ObjectType $type, string $templateName)
    {
        if (!$type instanceof Generic) {
            return null;
        }

        $definition = $this->infer->analyzeClass($type->name);

        $templateIndex = collect($definition->templateTypes)->search(fn ($t) => $t->name === $templateName);

        return $templateIndex !== false
            ? ($type->templateTypes[$templateIndex]) ?? null
            : null;
    }
}
