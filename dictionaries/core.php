<?php

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * @template TMessage of string = ''
 * @template TCode of int = 0
 * @template TPrevious of Throwable|null = null
 */
class Exception implements Throwable
{
    protected string $file;

    protected int $line;

    private function __clone(): void {}

    /**
     * @param  TMessage  $message
     * @param  TCode  $code
     * @param  TPrevious  $previous
     */
    public function __construct(
        protected string $message = '',
        protected int $code = 0,
        protected ?Throwable $previous = null
    ) {}

    /** @return TMessage */
    final public function getMessage(): string {}

    /** @return TCode */
    public function getCode() {}

    public function getFile(): string {}

    public function getLine(): int {}

    public function getTrace(): array {}

    /** @return TPrevious */
    public function getPrevious(): ?Throwable {}

    public function getTraceAsString(): string {}

    public function __toString(): string {}

    public function __wakeup(): void {}
}

class RuntimeException extends Exception {}

/**
 * @template TStatusCode of int
 * @template TMessage of string = ''
 * @template TCode of int = 0
 * @template TPrevious of Throwable|null = null
 * @template THeaders of array<string, mixed>
 *
 * @extends Exception<TMessage, TCode, TPrevious>
 */
#[NS('Symfony\Component\HttpKernel\Exception')]
class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @param TStatusCode $statusCode
     * @param TMessage $message
     * @param TPrevious $previous
     * @param THeaders $headers
     * @param TCode $code
     */
    public function __construct(
        private int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        private array $headers = [],
        int $code = 0,
    ) {
    }

    public static function fromStatusCode(int $statusCode, string $message = '', ?\Throwable $previous = null, array $headers = [], int $code = 0): self
    {}

    /** @return TStatusCode */
    public function getStatusCode(): int
    {}

    /** @return THeaders */
    public function getHeaders(): array
    {}

    /**
     * @template TNewHeaders of array<string, mixed>
     * @this-out static<_, _, _, _, TNewHeaders>
     * @param TNewHeaders $headers
     */
    public function setHeaders(array $headers): void
    {}
}
