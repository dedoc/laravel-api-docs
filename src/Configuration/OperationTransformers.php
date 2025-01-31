<?php

namespace Dedoc\Scramble\Configuration;

use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RequestEssentialsExtension;
use Dedoc\Scramble\Support\OperationExtensions\ResponseExtension;
use Illuminate\Support\Arr;

class OperationTransformers
{
    protected array $transformers = [];

    protected array $appends = [];

    protected array $prepends = [];

    public function append(array|callable|string $transformers)
    {
        $this->appends = array_merge(
            $this->appends,
            Arr::wrap($transformers)
        );

        return $this;
    }

    public function prepend(array|callable|string $transformers)
    {
        $this->prepends = array_merge(
            $this->prepends,
            Arr::wrap($transformers)
        );

        return $this;
    }

    public function use(array $transformers)
    {
        $this->transformers = $transformers;

        return $this;
    }

    public function all(): array
    {
        $base = $this->transformers ?: [
            RequestEssentialsExtension::class,
            RequestBodyExtension::class,
            ResponseExtension::class,
        ];

        return array_values(array_unique([
            ...$this->prepends,
            ...$base,
            ...$this->appends,
        ]));
    }
}
