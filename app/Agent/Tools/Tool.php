<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;

/**
 * A model-callable capability. The JSON schema is advisory for the model (OpenAI strict mode);
 * rules() is the authoritative server-side validation. handle() receives validated arguments and
 * an authenticated context — never a model-supplied user id.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> strict JSON schema (all properties required, nullable for optional) */
    public function parameters(): array;

    /** @return array<string, mixed> Laravel validation rules */
    public function rules(): array;

    /** Mutating tools are blocked outside user-initiated turns. */
    public function mutates(): bool;

    /** Whether the model is offered this tool in the given turn. Hidden tools cannot be called. */
    public function availableFor(ToolContext $context): bool;

    /** @param array<string, mixed> $args */
    public function handle(ToolContext $context, array $args): ToolResult;
}
