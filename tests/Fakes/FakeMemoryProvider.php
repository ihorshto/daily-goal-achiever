<?php

namespace Tests\Fakes;

use App\Memory\MemoryProvider;
use App\Memory\MemoryUnavailableException;
use App\Memory\ProviderMemory;
use Illuminate\Support\Str;

/** In-memory Mem0 stand-in with scope isolation, word-overlap scoring and a down switch. */
class FakeMemoryProvider implements MemoryProvider
{
    /** @var array<string, array<string, array{text: string, metadata: array, created_at: string}>> */
    public array $store = [];

    public bool $down = false;

    /** @var list<string> */
    public array $deleted = [];

    public int $adds = 0;

    public function add(string $scope, string $text, array $metadata): string
    {
        $this->guard();
        $id = (string) Str::uuid();
        $this->store[$scope][$id] = ['text' => $text, 'metadata' => $metadata, 'created_at' => now()->toIso8601String()];
        $this->adds++;

        return $id;
    }

    public function search(string $scope, string $query, int $limit): array
    {
        $this->guard();
        $q = $this->words($query);
        $out = [];
        foreach ($this->store[$scope] ?? [] as $id => $row) {
            $w = $this->words($row['text']);
            $overlap = count(array_intersect($q, $w));
            if ($overlap > 0) {
                $out[] = new ProviderMemory($id, $row['text'], min(1.0, $overlap / max(1, count($q))), $row['metadata'], $row['created_at']);
            }
        }
        usort($out, fn ($a, $b) => $b->score <=> $a->score);

        return array_slice($out, 0, $limit);
    }

    public function list(string $scope, int $limit): array
    {
        $this->guard();
        $out = [];
        foreach ($this->store[$scope] ?? [] as $id => $row) {
            $out[] = new ProviderMemory($id, $row['text'], null, $row['metadata'], $row['created_at']);
        }

        return array_slice($out, 0, $limit);
    }

    public function update(string $scope, string $id, string $text, array $metadata): void
    {
        $this->guard();
        if (isset($this->store[$scope][$id])) {
            $this->store[$scope][$id]['text'] = $text;
        }
    }

    public function delete(string $scope, string $id): void
    {
        $this->guard();
        unset($this->store[$scope][$id]);
        $this->deleted[] = $id;
    }

    public function deleteAll(string $scope): int
    {
        $this->guard();
        $n = count($this->store[$scope] ?? []);
        unset($this->store[$scope]);

        return $n;
    }

    public function healthy(): bool
    {
        return ! $this->down;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function count(string $scope): int
    {
        return count($this->store[$scope] ?? []);
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new MemoryUnavailableException('fake provider down');
        }
    }

    /** @return list<string> */
    private function words(string $s): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [], fn ($w) => mb_strlen($w) >= 4)));
    }
}
