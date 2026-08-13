<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeCommand extends GeneratorCommand
{
    protected $name = 'make:eval';

    protected $description = 'Create a new evaluation class';

    protected $type = 'Evaluation';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/vizra-evals/evaluation.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/evaluation.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Evals';
    }

    protected function buildClass($name): string
    {
        return str_replace('{{ dataFile }}', $this->dataFileName(), parent::buildClass($name));
    }

    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        $this->createDataFile();

        return null;
    }

    private function createDataFile(): void
    {
        $path = $this->laravel->basePath('evals/data/'.$this->dataFileName().'.jsonl');

        if (file_exists($path)) {
            $this->components->warn("Dataset [{$path}] already exists — leaving it alone.");

            return;
        }

        @mkdir(dirname($path), 0755, true);

        /*
         * Four starter rows, because the shape of the dataset teaches more
         * than the stub does.
         *
         * Row two shows `expected.any` — the generated evaluate() prefers it,
         * because matching model prose on one exact string fails on an en dash.
         * Row four shows a row the agent should refuse: a dataset of questions
         * it can obviously answer proves only that the API is up.
         */
        file_put_contents($path, implode("\n", [
            json_encode(['input' => 'Replace me with a real prompt', 'expected' => 'something the answer should contain']),
            json_encode(['input' => 'A question with more than one right wording', 'expected' => ['any' => ['30 days', 'thirty days']]]),
            json_encode(['messages' => [
                ['role' => 'user', 'content' => 'A multi-turn example'],
                ['role' => 'assistant', 'content' => 'Prior agent reply'],
                ['role' => 'user', 'content' => 'The final user turn becomes the prompt'],
            ]]),
            json_encode(['input' => 'Something your agent should refuse or admit it does not know', 'should_refuse' => true]),
        ])."\n");

        $this->components->info("Starter dataset created at [{$path}].");
    }

    private function dataFileName(): string
    {
        return Str::snake(class_basename($this->qualifyClass($this->getNameInput())));
    }
}
