<?php

namespace Vizra\Evals\Assertions\Structure;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class XmlHasTag implements Assertion
{
    public function __construct(private readonly string $tag) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($response->text);

            if ($xml === false) {
                return AssertionResult::fail('xml_has_tag', $this->tag, $response->text, 'Response is not valid XML.');
            }

            $found = $xml->getName() === $this->tag || $xml->xpath('//'.$this->tag) !== [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return AssertionResult::bool(
            'xml_has_tag',
            $found,
            $this->tag,
            $response->text,
            "XML response has no <{$this->tag}> tag.",
        );
    }
}
