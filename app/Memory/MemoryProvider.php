<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Semantic memory port. $scope is always derived server-side from the authenticated user
 * (see MemoryScope) — never from model output. Implementations throw
 * MemoryUnavailableException on transport/availability failures.
 */
interface MemoryProvider
{
    /** @param array<string, scalar|null> $metadata  @return string provider memory id */
    public function add(string $scope, string $text, array $metadata): string;

    /** @return list<ProviderMemory> */
    public function search(string $scope, string $query, int $limit): array;

    /** @return list<ProviderMemory> */
    public function list(string $scope, int $limit): array;

    /** @param array<string, scalar|null> $metadata */
    public function update(string $scope, string $id, string $text, array $metadata): void;

    /** Idempotent: deleting a missing memory is not an error. */
    public function delete(string $scope, string $id): void;

    /** @return int number of deleted memories */
    public function deleteAll(string $scope): int;

    public function healthy(): bool;

    public function name(): string;
}
