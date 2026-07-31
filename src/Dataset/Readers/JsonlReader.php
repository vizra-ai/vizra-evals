<?php

namespace Vizra\Evals\Dataset\Readers;

use Generator;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\DatasetException;

/**
 * Reads the package's preferred dataset format: JSON Lines.
 *
 * One JSON object per line. Recognised keys:
 *
 *   input     string — the user prompt (single-turn rows)
 *   messages  array  — [{role: "user"|"assistant", content: "..."}, ...];
 *                      the final entry must be a user turn and becomes the
 *                      prompt, with the preceding turns replayed as context
 *   expected  mixed  — free-form expected data, exposed via $row->expected()
 *   *         mixed  — any other key lands in $row->meta()
 *
 * Lines are streamed lazily; blank lines are skipped; a malformed line
 * aborts with its line number.
 */
class JsonlReader
{
    public function __construct(private readonly string $path) {}

    /**
     * @return Generator<int, Row>
     */
    public function rows(): Generator
    {
        if (! is_file($this->path)) {
            throw new DatasetException("JSONL dataset not found at [{$this->path}].");
        }

        $handle = fopen($this->path, 'r');

        try {
            $index = 0;
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);

                if (! is_array($data)) {
                    throw new DatasetException(
                        "Invalid JSON on line {$lineNumber} of [{$this->path}]: ".json_last_error_msg()
                    );
                }

                try {
                    yield Row::fromArray($data, $index++);
                } catch (DatasetException $e) {
                    throw new DatasetException("Line {$lineNumber} of [{$this->path}]: {$e->getMessage()}");
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
