<?php

namespace Vizra\Evals\Judge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Pairwise judge: given two responses, pick a winner. Response A is always
 * the actual response and B the reference (fixed order in v1 — position-bias
 * randomization is future work).
 */
class ComparisonJudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        You are an impartial evaluator comparing two AI responses, A and B,
        against given criteria. Choose the response that better satisfies the
        criteria. Answer "tie" only when they are genuinely indistinguishable
        in quality against the criteria. Do not favor length, ordering, or
        confident tone. Always explain the decisive differences.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'winner' => $schema->string()->enum(['a', 'b', 'tie'])->required()
                ->description('Which response better satisfies the criteria.'),
            'reasoning' => $schema->string()->required()
                ->description('The decisive differences between A and B.'),
        ];
    }
}
