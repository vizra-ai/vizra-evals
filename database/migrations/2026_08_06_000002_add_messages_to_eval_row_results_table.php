<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::table($prefix.'row_results', function (Blueprint $table) {
            /*
             * The turns that came before the prompt, for a multi-turn row.
             *
             * `input` already holds the whole exchange encoded as JSON, which
             * is right for reading but not for identity: a row is hashed over
             * (input, messages, expected) as three separate things, so
             * anything holding only the merged form cannot reconstruct the row
             * it is looking at.
             *
             * That is why a multi-turn row arrives in Vizra Cloud marked
             * incomplete — uneditable, unexportable, and rendered as raw JSON.
             * Not a policy, just a field nobody had persisted.
             */
            $table->json('messages')->nullable()->after('input');
        });
    }

    public function down(): void
    {
        $prefix = config('evals.table_prefix', 'eval_');

        Schema::table($prefix.'row_results', function (Blueprint $table) {
            $table->dropColumn('messages');
        });
    }
};
