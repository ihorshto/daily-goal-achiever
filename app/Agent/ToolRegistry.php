<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tools\Tool;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @param iterable<Tool> $tools */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** OpenAI function tool definitions (strict mode). @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'type' => 'function',
            'function' => [
                'name' => $t->name(),
                'description' => $t->description(),
                'parameters' => $t->parameters(),
                'strict' => true,
            ],
        ], $this->tools));
    }
}
