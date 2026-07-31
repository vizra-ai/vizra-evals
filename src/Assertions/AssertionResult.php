<?php

namespace Vizra\Evals\Assertions;

/**
 * The outcome of one assertion against one sample.
 *
 * Deliberately mutable: assertion helpers on the Evaluation base class record
 * the instance into the sample collector and return it, so fluent tails like
 * ->gate() and ->weight() modify the already-recorded object.
 */
final class AssertionResult
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const ERROR = 'error';

    public const SKIPPED = 'skipped';

    public const TYPE_DETERMINISTIC = 'deterministic';

    public const TYPE_JUDGE = 'judge';

    public function __construct(
        public string $name,
        public string $status,
        public ?float $score = null,
        public mixed $expected = null,
        public mixed $actual = null,
        public string $message = '',
        public float $weight = 1.0,
        public bool $isGate = false,
        public string $type = self::TYPE_DETERMINISTIC,
        public ?string $judgeReasoning = null,
        public array $meta = [],
    ) {}

    public static function pass(string $name, mixed $expected = null, mixed $actual = null, string $message = ''): self
    {
        return new self($name, self::PASSED, 1.0, $expected, $actual, $message);
    }

    public static function fail(string $name, mixed $expected = null, mixed $actual = null, string $message = ''): self
    {
        return new self($name, self::FAILED, 0.0, $expected, $actual, $message);
    }

    /**
     * $message is the failure explanation — it is only kept when the
     * assertion actually failed.
     */
    public static function bool(string $name, bool $passed, mixed $expected = null, mixed $actual = null, string $message = ''): self
    {
        return $passed
            ? self::pass($name, $expected, $actual)
            : self::fail($name, $expected, $actual, $message);
    }

    public static function error(string $name, string $message): self
    {
        return new self($name, self::ERROR, null, message: $message);
    }

    /**
     * Mark this assertion as a hard gate: if it fails, the sample fails
     * outright and judge assertions are skipped (configurable).
     */
    public function gate(): self
    {
        $this->isGate = true;

        return $this;
    }

    public function weight(float $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function passed(): bool
    {
        return $this->status === self::PASSED;
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'score' => $this->score,
            'weight' => $this->weight,
            'is_gate' => $this->isGate,
            'expected' => $this->stringify($this->expected),
            'actual' => $this->stringify($this->actual),
            'message' => $this->message,
            'judge_reasoning' => $this->judgeReasoning,
            'meta' => $this->meta === [] ? null : $this->meta,
        ];
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => var_export($value, true),
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
