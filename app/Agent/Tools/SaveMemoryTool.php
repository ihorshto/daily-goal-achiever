<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Enums\MemoryCategory;
use App\Enums\MemoryVerification;
use App\Memory\MemoryLedger;
use Illuminate\Validation\Rule;

final class SaveMemoryTool extends BaseTool
{
    public function __construct(private readonly MemoryLedger $ledger) {}

    public function name(): string
    {
        return 'save_memory';
    }

    public function description(): string
    {
        return 'Save a durable memory when the user explicitly asks you to remember something, or states an important lasting fact/preference/decision. One self-contained third-person sentence. Background extraction handles routine cases; do not save task status.';
    }

    public function parameters(): array
    {
        return $this->object([
            'content' => $this->string('The fact, e.g. "The user prefers deep work before noon."'),
            'category' => $this->enum(['fact', 'preference', 'decision', 'problem', 'resolution', 'goal_context', 'person', 'routine'], 'Category'),
        ]);
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'min:8', 'max:600'], 'category' => ['required', Rule::enum(MemoryCategory::class)]];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        [$memory, $outcome] = $this->ledger->record(
            $context->user, $args['content'], MemoryCategory::from($args['category']), 0.95,
            MemoryVerification::Stated, $context->sourceMessageId(), explicitUserRequest: true,
        );
        if ($memory === null) {
            return ToolResult::error("Not saved ({$outcome}).");
        }

        return ToolResult::ok(['memory_ref' => $memory->id, 'outcome' => $outcome, 'sync' => $memory->status->value]);
    }
}
