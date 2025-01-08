<?php

namespace Dedoc\Scramble\Support\ExceptionToResponseExtensions;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\LengthRequiredHttpException;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

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
        /*
         * So you (Roman from future) are wondering what 7 or 0 is.
         * When index is 7 – the type is honestly inferred – this the index of `TCode` template.
         * When index is 0 - the type is manually constructed in other extensions.
         */
        $codeType = count($type->templateTypes ?? []) > 3
            ? ($type->templateTypes[7] ?? null)
            : ($type->templateTypes[0] ?? null);

        $responseCode = $this->parseResponseCode($codeType, $type);
        if ($responseCode === null) {
            return null;
        }

        $responseBodyType = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'message',
                tap((new OpenApiTypes\StringType)->setDescription('Error overview.'), function (OpenApiTypes\StringType $t) use ($type) {
                    $messageType = $type->templateTypes[1] ?? null;
                    if (! $messageType instanceof LiteralStringType) {
                        return;
                    }
                    $t->example($messageType->value);
                })
            )
            ->setRequired(['message']);

        return Response::make($responseCode)
            ->description($this->parseDescription($type))
            ->setContent(
                'application/json',
                Schema::fromType($responseBodyType)
            );
    }

    protected function parseResponseCode(?Type $codeType, Type $type): ?int
    {
        if (! $codeType instanceof LiteralIntegerType) {
            return match (true) {
                $type->isInstanceOf(AccessDeniedHttpException::class) => 403,
                $type->isInstanceOf(BadRequestHttpException::class) => 400,
                $type->isInstanceOf(ConflictHttpException::class) => 409,
                $type->isInstanceOf(GoneHttpException::class) => 410,
                $type->isInstanceOf(LengthRequiredHttpException::class) => 411,
                $type->isInstanceOf(LockedHttpException::class) => 423,
                $type->isInstanceOf(MethodNotAllowedHttpException::class) => 405,
                $type->isInstanceOf(NotAcceptableHttpException::class) => 406,
                $type->isInstanceOf(NotFoundHttpException::class) => 404,
                $type->isInstanceOf(PreconditionFailedHttpException::class) => 412,
                $type->isInstanceOf(PreconditionRequiredHttpException::class) => 428,
                $type->isInstanceOf(ServiceUnavailableHttpException::class) => 503,
                $type->isInstanceOf(TooManyRequestsHttpException::class) => 429,
                $type->isInstanceOf(UnauthorizedHttpException::class) => 401,
                $type->isInstanceOf(UnprocessableEntityHttpException::class) => 422,
                $type->isInstanceOf(UnsupportedMediaTypeHttpException::class) => 415,

                default => 500,
            };
        }

        return $codeType->value;
    }
    protected function parseDescription(Type $type): string
    {

        return match (true) {
            $type->isInstanceOf(AccessDeniedHttpException::class) => 'Access Denied',
            $type->isInstanceOf(BadRequestHttpException::class) => 'Bad Request',
            $type->isInstanceOf(ConflictHttpException::class) => 'Conflict',
            $type->isInstanceOf(GoneHttpException::class) => 'Gone',
            $type->isInstanceOf(LengthRequiredHttpException::class) => 'Length Required',
            $type->isInstanceOf(LockedHttpException::class) => 'Locked',
            $type->isInstanceOf(MethodNotAllowedHttpException::class) => 'Method Not Allowed',
            $type->isInstanceOf(NotAcceptableHttpException::class) => 'Not Acceptable',
            $type->isInstanceOf(NotFoundHttpException::class) => 'Not Found',
            $type->isInstanceOf(PreconditionFailedHttpException::class) => 'Precondition Failed',
            $type->isInstanceOf(PreconditionRequiredHttpException::class) => 'Precondition Required',
            $type->isInstanceOf(ServiceUnavailableHttpException::class) => 'Service Unavailable',
            $type->isInstanceOf(TooManyRequestsHttpException::class) => 'Too Many Requests',
            $type->isInstanceOf(UnauthorizedHttpException::class) => 'Unauthorized',
            $type->isInstanceOf(UnprocessableEntityHttpException::class) => 'Unprocessable Entity',
            $type->isInstanceOf(UnsupportedMediaTypeHttpException::class) => 'Unsupported Media Type',

            default => 'An Error',
        };
    }
}
