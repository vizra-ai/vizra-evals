<?php

namespace Vizra\Evals\Dataset\Readers;

use Generator;
use League\Csv\Reader;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\DatasetException;

class CsvReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $inputColumn = 'prompt',
        private readonly string $expectedColumn = 'expected',
    ) {}

    /**
     * @return Generator<int, Row>
     */
    public function rows(): Generator
    {
        if (! is_file($this->path)) {
            throw new DatasetException("CSV dataset not found at [{$this->path}].");
        }

        $reader = Reader::createFromPath($this->path);
        $reader->setHeaderOffset(0);

        if (! in_array($this->inputColumn, $reader->getHeader(), true)) {
            throw new DatasetException(
                "CSV dataset [{$this->path}] has no \"{$this->inputColumn}\" column. Found: ".implode(', ', $reader->getHeader())
            );
        }

        $index = 0;

        foreach ($reader->getRecords() as $record) {
            $record = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $record);

            $data = ['input' => $record[$this->inputColumn]];

            if (array_key_exists($this->expectedColumn, $record) && $record[$this->expectedColumn] !== '') {
                $data['expected'] = $record[$this->expectedColumn];
            }

            foreach ($record as $key => $value) {
                if ($key !== $this->inputColumn && $key !== $this->expectedColumn) {
                    $data[$key] = $value;
                }
            }

            yield Row::fromArray($data, $index++);
        }
    }
}
