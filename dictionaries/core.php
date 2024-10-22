<?php

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
     * @param TMessage $message
     * @param TCode $code
     * @param TPrevious $previous
     */
    public function __construct(
        protected string $message = "",
        protected int $code = 0,
        protected ?Throwable $previous = null
    ) {}

    /** @return TMessage */
    final public function getMessage(): string {}

    /** @return TCode */
    public function getCode() {}

    public function getFile(): string {}

    public function getLine(): int {}

    /** @return array */
    public function getTrace(): array {}

    /** @return TPrevious */
    public function getPrevious(): ?Throwable {}

    public function getTraceAsString(): string {}

    public function __toString(): string {}

    public function __wakeup(): void {}
}

class RuntimeException extends Exception {}
