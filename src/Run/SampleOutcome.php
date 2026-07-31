<?php

namespace Vizra\Evals\Run;

use Laravel\Ai\Responses\AgentResponse;

/**
 * The result of one target invocation. Serializable, so concurrent
 * executors can produce it in a child process and return it to the parent.
 */
final class SampleOutcome
{
    private function __construct(
        public readonly ?AgentResponse $response,
        public readonly ?string $errorClass,
        public readonly ?string $error,
        public readonly float $durationMs,
    ) {}

    public static function success(AgentResponse $response, float $durationMs): self
    {
        return new self($response, null, null, $durationMs);
    }

    public static function error(string $errorClass, string $message, float $durationMs): self
    {
        return new self(null, $errorClass, $message, $durationMs);
    }

    public function failed(): bool
    {
        return $this->response === null;
    }
}
