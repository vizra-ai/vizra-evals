<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::table($prefix.'runs', function (Blueprint $table) {
            // The gate's verdict — {passed, failures} — as opposed to the
            // policy, which has always been recorded inside `config.gate`.
            // Until now the answer a gate produced lived only in the exit code
            // and a line of console output, so neither dashboard could say
            // whether a run passed, only what it scored.
            $table->json('gate')->nullable()->after('config');

            // The commit subject. A sha says which commit; this says what it
            // was, which is what makes a regression legible without leaving
            // the dashboard to go and look it up.
            $table->string('git_message')->nullable()->after('git_dirty');
        });
    }

    public function down(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::table($prefix.'runs', function (Blueprint $table) {
            $table->dropColumn(['gate', 'git_message']);
        });
    }
};
