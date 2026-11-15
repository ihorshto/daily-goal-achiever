<?php

declare(strict_types=1);

namespace App\Memory;

use App\Enums\MemoryVerification;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Semantic retrieval with strict tenant + tombstone filtering:
 * a Mem0 hit is only returned if it maps to a LIVE local memory row owned by this user.
 * Falls back to local keyword search when Mem0 is unavailable (degraded mode).
 */
final class MemoryRetriever
{
    public function __construct(private readonly MemoryProvider $provider) {}

    /** @return list<RetrievedMemory> */
    public function retrieve(User $user, string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $degraded = false;
        $hits = [];
        try {
            $providerHits = $this->provider->search(MemoryScope::for($user), $query, $limit * 2);
            $byMem0 = [];
            foreach ($providerHits as $hit) {
                $byMem0[$hit->id] = $hit;
            }
            if ($byMem0 !== []) {
                $rows = $user->memories()->live()->whereIn('mem0_id', array_keys($byMem0))->get();
                foreach ($rows as $row) {
                    $hits[] = $this->wrap($row, (float) ($byMem0[$row->mem0_id]->score ?? 0.5), 'semantic');
                }
            }
        } catch (MemoryUnavailableException $e) {
            $degraded = true;
            Log::warning('memory.retrieval_degraded', ['reason' => $e->getMessage()]);
        }

        // Local keyword search: always merged in for not-yet-synced rows, and the sole source when degraded.
        foreach ($this->keywordSearch($user, $query, $limit, onlyUnsynced: ! $degraded) as $row) {
            $hits[] = $this->wrap($row, 0.35, $degraded ? 'local_fallback' : 'local_unsynced');
        }

        return $this->rank($hits, $limit);
    }

    /** @return list<RetrievedMemory> */
    public function recent(User $user, int $limit = 20): array
    {
        return $user->memories()->live()->latest('id')->limit($limit)->get()
            ->map(fn (Memory $m) => $this->wrap($m, 1.0, 'local'))
            ->all();
    }

    /** @return Collection<int, Memory> */
    private function keywordSearch(User $user, string $query, int $limit, bool $onlyUnsynced)
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 4)
            ->unique()
            ->take(6);
        if ($terms->isEmpty()) {
            return collect();
        }
        $q = $user->memories()->live();
        if ($onlyUnsynced) {
            $q->whereNull('mem0_id');
        }

        return $q->where(function ($w) use ($terms) {
            foreach ($terms as $t) {
                $w->orWhereRaw('LOWER(content) LIKE ?', ['%'.addcslashes($t, '%_\\').'%']);
            }
        })->latest('id')->limit($limit)->get();
    }

    private function wrap(Memory $m, float $score, string $via): RetrievedMemory
    {
        return new RetrievedMemory(
            localId: $m->id,
            content: (string) $m->content,
            category: $m->category->value,
            recordedAt: $m->created_at->toDateString(),
            verification: $m->verification->value,
            score: $score,
            via: $via,
            createdAtTs: $m->created_at->getTimestamp(),
            hash: $m->content_hash,
            confirmed: $m->verification === MemoryVerification::Confirmed,
        );
    }

    /**
     * Dedupe by content hash, then rank: relevance, plus a small boost for recent and
     * user-stated/confirmed memories.
     *
     * @param  list<RetrievedMemory>  $hits
     * @return list<RetrievedMemory>
     */
    private function rank(array $hits, int $limit): array
    {
        $unique = [];
        foreach ($hits as $h) {
            if (! isset($unique[$h->hash]) || $unique[$h->hash]->score < $h->score) {
                $unique[$h->hash] = $h;
            }
        }
        $now = time();
        $scored = array_map(function (RetrievedMemory $h) use ($now) {
            $ageDays = max(0, ($now - $h->createdAtTs) / 86400);
            $recency = 0.15 * exp(-$ageDays / 30);
            $trust = $h->verification === 'inferred' ? -0.05 : 0.05;

            return [$h->score + $recency + $trust, $h];
        }, array_values($unique));
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_slice(array_map(fn ($s) => $s[1], $scored), 0, $limit);
    }
}
