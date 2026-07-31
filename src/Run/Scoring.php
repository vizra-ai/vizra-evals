<?php

namespace Vizra\Evals\Run;

use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Models\EvalRowResult;

/**
 * The scoring model, in one place:
 *
 *  - Gates are binary preconditions; a failed (or errored) gate hard-fails
 *    the sample with score 0.0 and is excluded from the score mean.
 *  - Non-gate assertions contribute their score (deterministic 1.0/0.0,
 *    judge 0..1) weighted by ->weight(); skipped/errored ones are excluded.
 *  - A sample with nothing scorable but all gates passing scores 1.0.
 */
class Scoring
{
    /**
     * @param  array<int, AssertionResult>  $assertions
     * @return array{status: string, score: ?float}
     */
    public static function sample(array $assertions): array
    {
        $gateFailed = false;
        $anyFailed = false;
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($assertions as $assertion) {
            $broken = in_array($assertion->status, [AssertionResult::FAILED, AssertionResult::ERROR], true);

            if ($assertion->isGate) {
                $gateFailed = $gateFailed || $broken;

                continue;
            }

            $anyFailed = $anyFailed || $broken;

            if ($assertion->score !== null) {
                $weightedSum += $assertion->score * $assertion->weight;
                $weightTotal += $assertion->weight;
            }
        }

        if ($gateFailed) {
            return ['status' => EvalRowResult::STATUS_FAILED, 'score' => 0.0];
        }

        $score = $weightTotal > 0.0 ? $weightedSum / $weightTotal : 1.0;

        return [
            'status' => $anyFailed ? EvalRowResult::STATUS_FAILED : EvalRowResult::STATUS_PASSED,
            'score' => round($score, 4),
        ];
    }

    /**
     * @param  array<int, float>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * Population standard deviation.
     *
     * @param  array<int, float>  $values
     */
    public static function stddev(array $values): ?float
    {
        $mean = self::mean($values);

        if ($mean === null) {
            return null;
        }

        $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / count($values);

        return sqrt($variance);
    }
}
