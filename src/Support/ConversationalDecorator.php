<?php

namespace Vizra\Evals\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Wraps a real agent so a dataset row's prior conversation turns can be
 * replayed through the SDK's only history channel, Conversational::messages().
 *
 * The decorator delegates instructions, tools, middleware, structured-output
 * schema, and provider options to the inner agent, so the prompt pipeline
 * behaves as if the inner agent were invoked directly. Two known losses,
 * both by construction:
 *
 *  - Class attributes (#[Provider], #[Model], #[Timeout]) are read via
 *    reflection on the *decorator*, so Target re-reads them from the inner
 *    class and passes them as explicit prompt() arguments instead.
 *  - #[Strict] structured-output mode on the inner class is not seen for
 *    multi-turn rows (rare combination; documented limitation).
 *
 * The decorator deliberately does not use the RemembersConversations trait,
 * so evaluation runs never write to the SDK's conversation tables.
 */
final class ConversationalDecorator implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  Message[]  $messages
     */
    public function __construct(
        public readonly Agent $inner,
        private readonly array $messages,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->inner->instructions();
    }

    public function messages(): iterable
    {
        // A statically Conversational inner agent contributes its seed
        // messages first; RemembersConversations agents are skipped as a
        // seed source because their messages() needs live conversation state.
        if ($this->inner instanceof Conversational
            && ! in_array(RemembersConversations::class, class_uses_recursive($this->inner))) {
            yield from $this->inner->messages();
        }

        yield from $this->messages;
    }

    public function tools(): iterable
    {
        return $this->inner instanceof HasTools ? $this->inner->tools() : [];
    }

    public function middleware(): array
    {
        return $this->inner instanceof HasMiddleware ? $this->inner->middleware() : [];
    }

    public function schema(JsonSchema $schema): array
    {
        // An empty schema makes the SDK fall back to a plain text response,
        // so implementing the contract unconditionally is safe.
        return $this->inner instanceof HasStructuredOutput ? $this->inner->schema($schema) : [];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->inner instanceof HasProviderOptions ? $this->inner->providerOptions($provider) : [];
    }
}
