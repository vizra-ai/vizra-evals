<?php

namespace Vizra\Evals\Run\Executors;

class SequentialExecutor implements Executor
{
    public function run(array $tasks): array
    {
        return array_map(fn ($task) => $task(), $tasks);
    }
}
