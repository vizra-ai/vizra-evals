<?php

namespace Vizra\Evals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvalAssertionResult extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'score' => 'float',
        'weight' => 'float',
        'is_gate' => 'boolean',
    ];

    public function getTable(): string
    {
        return config('evals.table_prefix', 'eval_').'assertion_results';
    }

    public function rowResult(): BelongsTo
    {
        return $this->belongsTo(EvalRowResult::class, 'row_result_id');
    }
}
