<?php

namespace Vizra\Evals;

use Closure;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use ReflectionClass;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\InvalidTargetException;
use Vizra\Evals\Support\Combo;
use Vizra\Evals\Support\ConversationalDecorator;

/**
 * Resolves what an evaluation invokes: a Laravel AI agent (class-string or
 * instance) or a Closure(Row, ?Combo): AgentResponse.
 */
final class Target
{
    private function __construct(
        private readonly ?string $agentClass,
        private readonly ?Agent $agentInstance,
        private readonly ?Closure $closure,
    ) {}

    public static function from(mixed $raw): self
    {
        return match (true) {
            $raw instanceof Closure => new self(null, null, $raw),
            $raw instanceof Agent => new self(null, $raw, null),
            is_string($raw) && is_a($raw, Agent::class, true) => new self($raw, null, null),
            default => throw new InvalidTargetException(
                'target() must return a Laravel\Ai agent class-string, an agent instance, or a Closure(Row): AgentResponse.'
            ),
        };
    }

    public function isClosure(): bool
    {
        return $this->closure !== null;
    }

    public function agentClass(): ?string
    {
        return $this->agentClass ?? ($this->agentInstance !== null ? $this->agentInstance::class : null);
    }

    public function invoke(Row $row, ?Combo $combo = null): AgentResponse
    {
        $combo ??= Combo::none();

        if ($this->closure !== null) {
            return ($this->closure)($row, $combo);
        }

        $agent = $this->resolveAgent();

        // SDK fakes are keyed by exact class, so a fake registered for the
        // agent would never match the decorator — multi-turn rows would
        // silently hit the real provider in tests. Since a fake gateway never
        // sees message history anyway, prompt the faked agent directly; this
        // also records prompts under the agent's own class for assertPrompted.
        if (! $row->isMultiTurn() || Ai::hasFakeGatewayFor($agent)) {
            return $agent->prompt(
                $row->input,
                provider: $combo->provider,
                model: $combo->model,
                timeout: config('evals.timeout'),
            );
        }

        $decorator = new ConversationalDecorator(
            $agent,
            array_map(fn (array $message) => Message::tryFrom($message), $row->messages),
        );

        // Promptable resolves #[Provider]/#[Model]/#[Timeout] by reflecting on
        // $this — the decorator — so re-read them from the inner class and pass
        // explicitly; explicit arguments always win over attributes.
        [$provider, $model, $timeout] = $this->innerAttributes($agent);

        return $decorator->prompt(
            $row->input,
            provider: $combo->provider ?? $provider,
            model: $combo->model ?? $model,
            timeout: config('evals.timeout') ?? $timeout,
        );
    }

    private function resolveAgent(): Agent
    {
        if ($this->agentInstance !== null) {
            // Clone per invocation so samples never share mutated agent state.
            return clone $this->agentInstance;
        }

        $class = $this->agentClass;

        return method_exists($class, 'make') ? $class::make() : app($class);
    }

    /**
     * @return array{0: mixed, 1: ?string, 2: ?int}
     */
    private function innerAttributes(Agent $agent): array
    {
        $reflection = new ReflectionClass($agent);

        $read = function (string $attribute) use ($reflection): mixed {
            $found = $reflection->getAttributes($attribute);

            return $found === [] ? null : $found[0]->newInstance()->value;
        };

        // Methods on the agent (provider()/model()/timeout()) outrank
        // attributes in the SDK, but the decorator has no such methods to
        // shadow them, so attribute values are the only thing lost.
        return [$read(Provider::class), $read(Model::class), $read(Timeout::class)];
    }
}
