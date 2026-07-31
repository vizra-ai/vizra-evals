<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::create($prefix.'assertion_results', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignUlid('row_result_id')->constrained($prefix.'row_results')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('deterministic');
            $table->string('status');
            $table->decimal('score', 6, 4)->nullable();
            $table->decimal('weight', 6, 2)->default(1);
            $table->boolean('is_gate')->default(false);
            $table->text('expected')->nullable();
            $table->text('actual')->nullable();
            $table->text('message')->nullable();
            $table->text('judge_reasoning')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('evals.table_prefix', 'eval_').'assertion_results');
    }
};
