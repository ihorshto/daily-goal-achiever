<?php

declare(strict_types=1);

namespace App\Memory;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the internal Mem0 sidecar (docker/mem0/app.py), which wraps the official
 * mem0ai OSS Python SDK (Memory.from_config + pgvector). The HTTP contract below is OURS,
 * defined in the sidecar; it is not a Mem0-published REST API.
 */
final class Mem0MemoryProvider implements MemoryProvider
{
    public function add(string $scope, string $text, array $metadata): string
    {
        $response = $this->send(fn (PendingRequest $h) => $h->post('/v1/memories', [
            'scope' => $scope,
            'text' => $text,
            'metadata' => (object) $metadata,
        ]));
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new MemoryUnavailableException('Mem0 sidecar returned no id');
        }

        return $id;
    }

    public function search(string $scope, string $query, int $limit): array
    {
        $response = $this->send(fn (PendingRequest $h) => $h->post('/v1/memories/search', [
            'scope' => $scope,
            'query' => mb_substr($query, 0, 2000),
            'limit' => $limit,
            'threshold' => (float) config('companion.memory.mem0.search_threshold'),
        ]));

        return $this->mapResults($response, $scope);
    }

    public function list(string $scope, int $limit): array
    {
        $response = $this->send(fn (PendingRequest $h) => $h->get('/v1/memories', ['scope' => $scope, 'limit' => $limit]));

        return $this->mapResults($response, $scope);
    }

    public function update(string $scope, string $id, string $text, array $metadata): void
    {
        $this->send(fn (PendingRequest $h) => $h->put('/v1/memories/'.rawurlencode($id), [
            'scope' => $scope, 'text' => $text, 'metadata' => (object) $metadata,
        ]));
    }

    public function delete(string $scope, string $id): void
    {
        $this->send(fn (PendingRequest $h) => $h->delete('/v1/memories/'.rawurlencode($id).'?scope='.rawurlencode($scope)), allowNotFound: true);
    }

    public function deleteAll(string $scope): int
    {
        $response = $this->send(fn (PendingRequest $h) => $h->delete('/v1/scopes/'.rawurlencode($scope).'/memories'));

        return (int) $response->json('deleted', 0);
    }

    public function healthy(): bool
    {
        try {
            return $this->client()->timeout(3)->get('/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function name(): string
    {
        return 'mem0';
    }

    /** @return list<ProviderMemory> */
    private function mapResults(Response $response, string $scope): array
    {
        $out = [];
        foreach ((array) $response->json('results', []) as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['memory'])) {
                continue;
            }
            // Defense in depth: the sidecar filters by scope, and we re-check here.
            if (($row['scope'] ?? $scope) !== $scope) {
                Log::error('mem0.scope_mismatch');

                continue;
            }
            $out[] = new ProviderMemory(
                id: (string) $row['id'],
                text: (string) $row['memory'],
                score: isset($row['score']) ? (float) $row['score'] : null,
                metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                createdAt: $row['created_at'] ?? null,
            );
        }

        return $out;
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, bool $allowNotFound = false): Response
    {
        try {
            $response = $call($this->client());
        } catch (ConnectionException $e) {
            throw new MemoryUnavailableException('Mem0 sidecar unreachable');
        }
        if ($allowNotFound && $response->status() === 404) {
            return $response;
        }
        if (! $response->successful()) {
            Log::warning('mem0.error', ['status' => $response->status()]);
            throw new MemoryUnavailableException('Mem0 sidecar error '.$response->status());
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        $key = (string) config('companion.memory.mem0.api_key');
        if ($key === '') {
            throw new MemoryUnavailableException('MEM0_API_KEY is not configured');
        }

        return Http::baseUrl(rtrim((string) config('companion.memory.mem0.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('companion.memory.mem0.timeout_seconds'))
            ->connectTimeout(3);
    }
}
