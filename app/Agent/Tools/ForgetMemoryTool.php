<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Memory\MemoryLedger;

final class ForgetMemoryTool extends BaseTool
{
    public function __construct(private readonly MemoryLedger $ledger) {}

    public function name(): string
    {
        return 'forget_memory';
    }

    public function description(): string
    {
        return 'Permanently delete one saved memory by memory_ref when the user asks to forget it. Deletion propagates to semantic memory and is audited.';
    }

    public function parameters(): array
    {
        return $this->object(['memory_ref' => $this->integer('memory_ref from list_memories/search_memory')]);
    }

    public function rules(): array
    {
        return ['memory_ref' => ['required', 'integer', 'min:1']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $memory = $context->user->memories()->live()->whereKey($args['memory_ref'])->first();
        if ($memory === null) {
            return ToolResult::error('No such memory.');
        }
        $request = $this->ledger->forget($context->user, $memory);

        return ToolResult::ok(['deleted' => true, 'deletion_request_id' => $request->id, 'provider_propagation' => $request->status]);
    }
}
