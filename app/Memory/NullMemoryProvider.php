<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Explicitly disabled semantic memory (MEMORY_DRIVER=null). Writes are refused so they stay
 * queued as pending_sync locally; retrieval falls back to local metadata search.
 */
final class NullMemoryProvider implements MemoryProvider
{
    public function add(string $scope, string $text, array $metadata): string
    {
        throw new MemoryUnavailableException('Semantic memory provider disabled');
    }

    public function search(string $scope, string $query, int $limit): array
    {
        throw new MemoryUnavailableException('Semantic memory provider disabled');
    }

    public function list(string $scope, int $limit): array
    {
        return [];
    }

    public function update(string $scope, string $id, string $text, array $metadata): void
    {
        throw new MemoryUnavailableException('Semantic memory provider disabled');
    }

    public function delete(string $scope, string $id): void {}

    public function deleteAll(string $scope): int
    {
        return 0;
    }

    public function healthy(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }
}
