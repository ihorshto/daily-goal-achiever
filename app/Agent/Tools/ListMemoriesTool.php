<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\ToolContext;
use App\Agent\ToolResult;
use App\Memory\MemoryRetriever;
use App\Memory\RetrievedMemory;

final class ListMemoriesTool extends BaseTool
{
    public function __construct(private readonly MemoryRetriever $retriever) {}

    public function name(): string
    {
        return 'list_memories';
    }

    public function description(): string
    {
        return 'List the most recent saved memories (with memory_ref ids) so the user can review or delete them.';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return $this->object(['limit' => $this->integer('Max items (default 15)', true)]);
    }

    public function rules(): array
    {
        return ['limit' => ['nullable', 'integer', 'min:1', 'max:50']];
    }

    public function handle(ToolContext $context, array $args): ToolResult
    {
        $items = $this->retriever->recent($context->user, $args['limit'] ?? 15);

        return ToolResult::ok([
            'memories' => array_map(fn (RetrievedMemory $m) => $m->toArray(), $items),
            'total_live' => $context->user->memories()->live()->count(),
        ]);
    }
}
