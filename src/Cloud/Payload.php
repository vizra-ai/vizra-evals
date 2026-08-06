<?php

namespace Vizra\Evals\Cloud;

use Vizra\Evals\Models\EvalAssertionResult;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;

/**
 * Turns a finished local run into the document the cloud ingests.
 *
 * This is deliberately NOT the same shape as `evals:run --output=json`. That
 * document is for humans and jq: samples nest inside their row, git is a
 * sub-object, and it is free to change whenever the CLI output improves. The
 * wire format is a contract with a server that will be running an older or
 * newer version than the client, so it is flat, versioned, and changes only
 * additively.
 */
class Payload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(EvalRun $run, bool $withSamples = true): array
    {
        $ci = Environment::ci();
        $perRow = $run->summary['per_row'] ?? [];

        return [
            'version' => 1,
            'run' => [
                'id' => $run->id,
                'suite' => $run->suite,
                'name' => $run->name,
                'status' => $run->status,
                'environment' => Environment::name(),
                'git_sha' => $run->git_sha,
                // CI checks out a detached HEAD, so `git rev-parse
                // --abbrev-ref HEAD` says "HEAD" and the branch has to come
                // from the provider instead.
                'git_branch' => $run->git_branch === 'HEAD' ? $ci['branch'] : ($run->git_branch ?: $ci['branch']),
                'git_dirty' => $run->git_dirty,
                'git_message' => $run->git_message,
                'ci_provider' => $ci['provider'],
                'ci_build_url' => $ci['build_url'],
                'pull_request' => $ci['pull_request'],
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'config' => $run->config,
            ],
            // The verdict, not the policy — the policy has always travelled
            // inside `run.config.gate`. This is what CI acted on, and it is
            // the difference between a dashboard that reports a score and one
            // that reports an answer.
            'gate' => $run->gate,
            'summary' => [
                'score_mean' => $run->score,
                'pass_rate' => $run->pass_rate,
                'total_rows' => $run->total_rows,
                'total_samples' => $run->total_samples,
                'error_count' => $run->error_count,
                'total_cost' => $run->total_cost,
                'judge_cost' => $run->judge_cost,
            ],
            'rows' => self::rows($perRow),
            'samples' => $withSamples ? self::samples($run) : [],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $perRow
     * @return list<array<string, mixed>>
     */
    private static function rows(array $perRow): array
    {
        return array_values(array_map(fn (array $row) => [
            'row_hash' => $row['row_hash'],
            'combo_key' => $row['combo_key'],
            'row_index' => $row['row_index'] ?? null,
            'input_preview' => $row['input_preview'] ?? null,
            'score_mean' => $row['score_mean'] ?? null,
            'score_stddev' => $row['score_stddev'] ?? null,
            'pass_rate' => $row['pass_rate'] ?? null,
            'samples' => $row['samples'] ?? 0,
            'passed' => $row['passed'] ?? 0,
            'errors' => $row['errors'] ?? 0,
            'cost' => $row['cost'] ?? null,
        ], $perRow));
    }

    /**
     * Per-sample detail: verbatim model input and output.
     *
     * This is the part a team may not be allowed to send anywhere, which is
     * why the caller decides whether it is included at all. Without it the
     * cloud can still say a row regressed; with it, it can say why.
     *
     * Streamed with a cursor because a matrix run over a large dataset is
     * thousands of rows and hydrating them all costs more memory than the
     * eval itself did.
     *
     * @return list<array<string, mixed>>
     */
    private static function samples(EvalRun $run): array
    {
        $samples = [];

        foreach ($run->rowResults()->with('assertionResults')->orderBy('sample_index')->cursor() as $sample) {
            /** @var EvalRowResult $sample */
            $samples[] = [
                'row_hash' => $sample->row_hash,
                'combo_key' => $sample->combo_key,
                'sample_index' => $sample->sample_index,
                'status' => $sample->status,
                'score' => $sample->score,
                'input' => $sample->input,
                'expected' => $sample->expected,
                'response_text' => $sample->response_text,
                'structured_output' => $sample->structured_output,
                'tool_calls' => $sample->tool_calls,
                'usage' => $sample->usage,
                'finish_reason' => $sample->finish_reason,
                'error' => $sample->error,
                'cost' => $sample->cost,
                'duration_ms' => $sample->duration_ms,
                'assertions' => $sample->assertionResults
                    ->map(fn (EvalAssertionResult $assertion) => [
                        'name' => $assertion->name,
                        'type' => $assertion->type,
                        'status' => $assertion->status,
                        'score' => $assertion->score,
                        'weight' => $assertion->weight,
                        'is_gate' => $assertion->is_gate,
                        'expected' => $assertion->expected,
                        'actual' => $assertion->actual,
                        'message' => $assertion->message,
                        'judge_reasoning' => $assertion->judge_reasoning,
                    ])->all(),
            ];
        }

        return $samples;
    }
}
