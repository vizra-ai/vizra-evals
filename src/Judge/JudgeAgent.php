<?php

namespace Vizra\Evals\Judge;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The default LLM judge: a structured-output agent returning
 * {score: 1-10, reasoning} plus, when requested, per-dimension scores.
 * Structured output replaces the regex response-parsing this design
 * inherited from — there is nothing to parse.
 */
class JudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<int, string>  $dimensionNames
     */
    public function __construct(public array $dimensionNames = []) {}

    public function instructions(): string
    {
        return <<<'PROMPT'
        You are an impartial evaluator grading an AI agent's response against
        given criteria. Judge only what is asked by the criteria; do not
        reward verbosity, style, or confidence for their own sake. Be strict:
        a 10 means the response could not reasonably be improved against the
        criteria, a 5 means it partially satisfies them, a 1 means it fails
        them entirely. Always explain the concrete reasons for your score.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [
            'score' => $schema->integer()->min(1)->max(10)->required()
                ->description('Overall score against the criteria, 1-10.'),
            'reasoning' => $schema->string()->required()
                ->description('Concrete justification for the scores.'),
        ];

        if ($this->dimensionNames !== []) {
            $dimensions = [];

            foreach ($this->dimensionNames as $name) {
                $dimensions[$name] = $schema->integer()->min(1)->max(10)->required();
            }

            $fields['dimensions'] = $schema->object($dimensions)->required();
        }

        return $fields;
    }
}
