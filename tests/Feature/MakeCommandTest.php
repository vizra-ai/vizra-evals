<?php

use Illuminate\Support\Facades\File;

it('generates an evaluation class and a starter dataset', function () {
    $classPath = app_path('Evals/CheckoutFlowEvaluation.php');
    $dataPath = base_path('evals/data/checkout_flow_evaluation.jsonl');

    File::delete([$classPath, $dataPath]);

    $this->artisan('make:eval', ['name' => 'CheckoutFlowEvaluation'])->assertExitCode(0);

    expect(File::exists($classPath))->toBeTrue()
        ->and(File::get($classPath))->toContain('class CheckoutFlowEvaluation extends Evaluation')
        ->and(File::get($classPath))->toContain('checkout_flow_evaluation.jsonl')
        ->and(File::exists($dataPath))->toBeTrue();

    $lines = array_filter(explode("\n", File::get($dataPath)));

    foreach ($lines as $line) {
        expect(json_decode($line, true))->toBeArray();
    }

    File::delete([$classPath, $dataPath]);
    File::deleteDirectory(base_path('evals'));
});
