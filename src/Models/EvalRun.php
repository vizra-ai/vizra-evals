<?php

namespace Vizra\Evals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalRun extends Model
{
    use HasUlids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'gate' => 'array',
        'summary' => 'array',
        'score' => 'float',
        'pass_rate' => 'float',
        'total_rows' => 'integer',
        'total_samples' => 'integer',
        'error_count' => 'integer',
        'total_cost' => 'float',
        'judge_cost' => 'float',
        'git_dirty' => 'boolean',
        'is_baseline' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('evals.table_prefix', 'eval_').'runs';
    }

    public function rowResults(): HasMany
    {
        return $this->hasMany(EvalRowResult::class, 'run_id');
    }

    /**
     * Record what the gate decided about this run.
     *
     * The gate is evaluated in two places — the console command and the Pest
     * expectation — and both need the verdict to outlive the process. It is
     * the answer CI acted on, so a dashboard that cannot show it is left
     * describing a score and leaving the reader to guess the threshold.
     *
     * @param  array{passed: bool, failures: array<int, string>}  $outcome
     */
    public function recordGate(array $outcome): void
    {
        $this->forceFill(['gate' => $outcome])->save();
    }

    public static function baselineFor(string $suite): ?self
    {
        return static::query()
            ->where('suite', $suite)
            ->where('is_baseline', true)
            ->latest('created_at')
            ->first();
    }

    public static function latestCompletedFor(string $suite): ?self
    {
        return static::query()
            ->where('suite', $suite)
            ->where('status', self::STATUS_COMPLETED)
            ->latest('created_at')
            ->first();
    }
}
