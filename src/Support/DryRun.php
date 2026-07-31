<?php

namespace Vizra\Evals\Support;

use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\StructuredAnonymousAgent;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Judge\ComparisonJudgeAgent;
use Vizra\Evals\Judge\JudgeAgent;
use Vizra\Evals\Target;

/**
 * Registers empty fakes for everything an evaluation might invoke, so the
 * whole suite executes with zero network calls and zero token cost. Empty
 * fake arrays make the SDK auto-generate responses (including schema-shaped
 * structured output), which is enough to validate datasets, wiring, and
 * assertion plumbing.
 */
class DryRun
{
    public static function fake(Evaluation $evaluation, Target $target): void
    {
        $classes = array_filter([
            $target->agentClass(),
            ConversationalDecorator::class,
            JudgeAgent::class,
            ComparisonJudgeAgent::class,
            config('evals.judge.agent'),
            AnonymousAgent::class,
            StructuredAnonymousAgent::class,
        ]);

        foreach (array_unique($classes) as $class) {
            Ai::fakeAgent($class, []);
        }
    }
}
