<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class NoPendingApprovals implements Assertion
{
    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $pending = $response->pendingApprovals->count();

        return AssertionResult::bool(
            'no_pending_approvals',
            $pending === 0,
            'no pending tool approvals',
            "{$pending} pending approvals",
            "Response paused with {$pending} pending tool approvals.",
        );
    }
}
