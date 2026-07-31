<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::create($prefix.'runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('suite')->index();
            $table->string('name')->nullable();
            $table->string('status')->default('running');
            $table->string('git_sha', 40)->nullable();
            $table->string('git_branch')->nullable();
            $table->boolean('git_dirty')->nullable();
            $table->json('config');
            $table->json('summary')->nullable();
            $table->decimal('score', 6, 4)->nullable();
            $table->decimal('pass_rate', 6, 4)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('total_samples')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->decimal('total_cost', 12, 6)->nullable();
            $table->decimal('judge_cost', 12, 6)->nullable();
            $table->boolean('is_baseline')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['suite', 'created_at']);
            $table->index(['suite', 'is_baseline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('evals.table_prefix', 'eval_').'runs');
    }
};
