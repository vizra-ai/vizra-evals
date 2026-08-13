<?php

namespace Vizra\Evals\Run;

/**
 * Run-level pass/fail policy. Scores here are 0..1 aggregates, applied
 * after the run completes; max_regressions only applies when the run is
 * compared against a reference run.
 */
final class Gate
{
    public function __construct(
        public readonly ?float $minScore = null,
        public readonly ?float $minPassRate = null,
        public readonly int $maxRegressions = 0,
    ) {}

    public static function minScore(float $score): self
    {
        return new self(minScore: $score);
    }

    public static function minPassRate(float $rate): self
    {
        return new self(minPassRate: $rate);
    }

    public static function maxRegressions(int $count): self
    {
        return new self(maxRegressions: $count);
    }

    public static function fromConfig(): self
    {
        return new self(
            config('evals.gate.min_score'),
            config('evals.gate.min_pass_rate'),
            (int) config('evals.gate.max_regressions', 0),
        );
    }

    public function with(?float $minScore = null, ?float $minPassRate = null, ?int $maxRegressions = null): self
    {
        return new self(
            $minScore ?? $this->minScore,
            $minPassRate ?? $this->minPassRate,
            $maxRegressions ?? $this->maxRegressions,
        );
    }

    /**
     * Does this gate assert anything about the run on its own?
     *
     * `maxRegressions` does not count: it only bites when there is something
     * to compare against. A gate with neither threshold set passes every run,
     * including one where every sample failed — which reads as a green build
     * to anyone who wired it into CI without setting a threshold.
     */
    public function constrainsScore(): bool
    {
        return $this->minScore !== null || $this->minPassRate !== null;
    }

    /**
     * @return array{passed: bool, failures: array<int, string>}
     */
    public function evaluate(RunResult $result, ?Comparison $comparison = null): array
    {
        $failures = [];

        if ($this->minScore !== null && ($result->score() === null || $result->score() < $this->minScore)) {
            $failures[] = sprintf('score %.4f is below the minimum %.4f', $result->score() ?? 0.0, $this->minScore);
        }

        if ($this->minPassRate !== null && ($result->passRate() === null || $result->passRate() < $this->minPassRate)) {
            $failures[] = sprintf('pass rate %.4f is below the minimum %.4f', $result->passRate() ?? 0.0, $this->minPassRate);
        }

        if ($comparison !== null && count($comparison->regressed) > $this->maxRegressions) {
            $failures[] = sprintf(
                '%d rows regressed against the reference run (allowed: %d)',
                count($comparison->regressed),
                $this->maxRegressions,
            );
        }

        return ['passed' => $failures === [], 'failures' => $failures];
    }

    public function toArray(): array
    {
        return [
            'min_score' => $this->minScore,
            'min_pass_rate' => $this->minPassRate,
            'max_regressions' => $this->maxRegressions,
        ];
    }
}
