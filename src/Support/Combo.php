<?php

namespace Vizra\Evals\Support;

use Laravel\Ai\Enums\Lab;

/**
 * One cell of an evaluation's across() matrix: a provider/model pair the
 * whole dataset is run against. A "none" combo defers to the agent's own
 * provider and model resolution.
 */
final class Combo
{
    public function __construct(
        public readonly Lab|string|null $provider = null,
        public readonly ?string $model = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<int, array{provider?: Lab|string, model?: string}>  $across
     * @return array<int, self>
     */
    public static function matrix(array $across): array
    {
        if ($across === []) {
            return [self::none()];
        }

        return array_map(
            fn (array $combo) => new self($combo['provider'] ?? null, $combo['model'] ?? null),
            array_values($across)
        );
    }

    public function isNone(): bool
    {
        return $this->provider === null && $this->model === null;
    }

    public function providerName(): ?string
    {
        return $this->provider instanceof Lab ? $this->provider->value : $this->provider;
    }

    public function key(): string
    {
        if ($this->isNone()) {
            return '-';
        }

        return ($this->providerName() ?? '?').'/'.($this->model ?? '?');
    }

    public function toArray(): ?array
    {
        if ($this->isNone()) {
            return null;
        }

        return ['provider' => $this->providerName(), 'model' => $this->model];
    }
}
