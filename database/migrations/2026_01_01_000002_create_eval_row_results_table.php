<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::create($prefix.'row_results', function (Blueprint $table) use ($prefix) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained($prefix.'runs')->cascadeOnDelete();
            $table->string('row_hash', 64);
            $table->unsignedInteger('row_index');
            $table->unsignedSmallInteger('sample_index');
            $table->json('combo')->nullable();
            $table->string('combo_key')->default('-');
            $table->text('input');
            $table->text('expected')->nullable();
            $table->json('meta')->nullable();
            $table->string('status');
            $table->decimal('score', 6, 4)->nullable();
            $table->longText('response_text')->nullable();
            $table->json('structured_output')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('usage')->nullable();
            $table->string('finish_reason')->nullable();
            $table->decimal('cost', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['run_id', 'row_hash', 'combo_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('evals.table_prefix', 'eval_').'row_results');
    }
};
