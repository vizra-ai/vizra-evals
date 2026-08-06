<?php

namespace Vizra\Evals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalRowResult extends Model
{
    use HasUlids;

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ERROR = 'error';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'combo' => 'array',
        'messages' => 'array',
        'meta' => 'array',
        'structured_output' => 'array',
        'tool_calls' => 'array',
        'usage' => 'array',
        'score' => 'float',
        'cost' => 'float',
        'row_index' => 'integer',
        'sample_index' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function getTable(): string
    {
        return config('evals.table_prefix', 'eval_').'row_results';
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'run_id');
    }

    public function assertionResults(): HasMany
    {
        return $this->hasMany(EvalAssertionResult::class, 'row_result_id');
    }
}
