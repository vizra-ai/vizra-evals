<?php

use Vizra\Evals\Judge\JudgeAgent;

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluation Discovery Paths
    |--------------------------------------------------------------------------
    |
    | Directories scanned by `evals:run` (with no argument) to discover
    | Evaluation classes. Subdirectories are included.
    |
    */

    'paths' => [
        // app_path('Evals') is resolved lazily by Discovery, since this config
        // file may be loaded before the app path helpers are available.
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    */

    'table_prefix' => env('EVALS_TABLE_PREFIX', 'eval_'),

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    |
    | Number of target invocations run concurrently (via Laravel's Concurrency
    | facade, process driver). Set to 1 for fully sequential execution.
    | Dry runs always execute sequentially because agent fakes are held
    | in process memory.
    |
    */

    'concurrency' => env('EVALS_CONCURRENCY', 5),

    /*
    |--------------------------------------------------------------------------
    | Per-Prompt Timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => env('EVALS_TIMEOUT'),

    /*
    |--------------------------------------------------------------------------
    | LLM Judge
    |--------------------------------------------------------------------------
    |
    | Defaults for judge()-based assertions. Prefer pointing the judge at a
    | different model family than the agents under evaluation: models grade
    | their own family's output leniently (self-grading bias).
    |
    */

    'judge' => [
        'agent' => JudgeAgent::class,
        'provider' => env('EVALS_JUDGE_PROVIDER'),
        'model' => env('EVALS_JUDGE_MODEL'),
        'min_score' => 7,
        'skip_on_gate_failure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Run-Level Gate Defaults
    |--------------------------------------------------------------------------
    |
    | Applied when an Evaluation does not define gatePolicy() and no CLI
    | override is given. Null disables that check. max_regressions is only
    | enforced when --compare is used.
    |
    */

    'gate' => [
        'min_score' => null,      // 0..1 against the run's aggregate score
        'min_pass_rate' => null,  // 0..1
        'max_regressions' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Baseline Comparison
    |--------------------------------------------------------------------------
    |
    | epsilon: minimum drop in a row's mean score (0..1) counted as a
    | regression. Pass-rate drops always count.
    |
    */

    'compare' => [
        'epsilon' => 0.05,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (USD per 1 million tokens)
    |--------------------------------------------------------------------------
    |
    | Used to estimate per-sample cost from the SDK's Usage object. THIS TABLE
    | IS MAINTAINED BY YOU — prices change and this package does not attempt
    | to keep them current. Unknown provider/model combinations produce a null
    | cost and a one-time console warning, never an error.
    |
    | Keys per model: input, output, cache_read, cache_write (all optional
    | except input/output). Reasoning tokens are billed at the output rate.
    |
    */

    'pricing' => [
        'anthropic' => [
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10, 'cache_write' => 1.25],
        ],
        'openai' => [
            'gpt-5' => ['input' => 1.25, 'output' => 10.00, 'cache_read' => 0.125],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00, 'cache_read' => 0.025],
            'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cache_read' => 1.25],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vizra Cloud
    |--------------------------------------------------------------------------
    |
    | Set VIZRA_CLOUD_KEY and every finished run is pushed to the hosted
    | dashboard, giving you shared history, baselines that outlive a laptop,
    | and PR checks. Leave it unset and nothing is sent anywhere.
    |
    | `samples` controls whether per-sample detail travels with the run. That
    | detail is verbatim model input and output — it is what makes the hosted
    | drill-down work, and it is also the one part some teams are not allowed
    | to send off-site. Set VIZRA_CLOUD_SAMPLES=false to send scores only.
    |
    | Reporting never changes a run's outcome. If the upload fails you get a
    | warning on stderr and the exit code the gate decided.
    |
    */

    'cloud' => [
        'endpoint' => env('VIZRA_CLOUD_ENDPOINT', 'https://vizra.ai/api/v1/runs'),
        'key' => env('VIZRA_CLOUD_KEY'),
        'samples' => env('VIZRA_CLOUD_SAMPLES', true),
        'timeout' => env('VIZRA_CLOUD_TIMEOUT', 15),

        // Which environment runs are filed under. Left null, anything in CI
        // is "ci" and everything else uses APP_ENV.
        'environment' => env('VIZRA_CLOUD_ENVIRONMENT'),

        // With a key set, the package adds `evals:runner` to your scheduler
        // for you, so the Run button in the dashboard works without you
        // adding anything. It rides on the cron that already runs
        // schedule:run. Set false to register it yourself instead.
        'auto_schedule' => env('VIZRA_CLOUD_AUTO_SCHEDULE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Assertions
    |--------------------------------------------------------------------------
    |
    | Extra words for assertContainsNoBlockedWords(), merged with the
    | built-in list. This is a plain wordlist check — a cheap pre-filter,
    | not a toxicity classifier.
    |
    */

    'safety' => [
        'blocked_words' => [],
    ],

];
