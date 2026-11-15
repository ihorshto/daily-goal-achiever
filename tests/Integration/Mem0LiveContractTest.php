<?php

namespace Tests\Integration;

use App\Memory\Mem0MemoryProvider;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Live contract test against a running Mem0 sidecar (real mem0ai SDK + real pgvector).
 * Skipped unless MEM0_CONTRACT_URL and MEM0_CONTRACT_KEY are set, e.g.:
 *   MEM0_CONTRACT_URL=http://127.0.0.1:8001 MEM0_CONTRACT_KEY=... php artisan test --group=mem0-live
 * With MEM0_TEST_EMBEDDER=1 on the sidecar no OpenAI credentials are needed.
 */
#[Group('mem0-live')]
class Mem0LiveContractTest extends TestCase
{
    private Mem0MemoryProvider $provider;

    private string $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('MEM0_CONTRACT_URL');
        $key = getenv('MEM0_CONTRACT_KEY');
        if (! $url || ! $key) {
            $this->markTestSkipped('Set MEM0_CONTRACT_URL and MEM0_CONTRACT_KEY to run against a live sidecar.');
        }
        config(['companion.memory.mem0.base_url' => $url, 'companion.memory.mem0.api_key' => $key, 'companion.memory.mem0.search_threshold' => 0.05]);
        $this->provider = new Mem0MemoryProvider;
        $this->scope = 'contract:user:'.random_int(1_000_000, 9_999_999);
    }

    protected function tearDown(): void
    {
        if (isset($this->provider)) {
            $this->provider->deleteAll($this->scope);
            $this->provider->deleteAll($this->scope.'1');
        }
        parent::tearDown();
    }

    public function test_full_lifecycle_against_real_mem0(): void
    {
        $this->assertTrue($this->provider->healthy());

        $a = $this->provider->add($this->scope, 'The deployment was delayed because the postgres migration locked the users table.', ['local_id' => 1, 'category' => 'resolution']);
        $b = $this->provider->add($this->scope, 'The user prefers green tea in the afternoon.', ['local_id' => 2, 'category' => 'preference']);
        $this->assertTrue(Str::isUuid($a));

        $hits = $this->provider->search($this->scope, 'why was the deployment delayed postgres migration', 5);
        $this->assertNotEmpty($hits);
        $this->assertSame($a, $hits[0]->id);
        $this->assertSame(1, (int) $hits[0]->metadata['local_id']);

        // Tenant isolation: another scope sees nothing.
        $other = $this->scope.'1';
        $this->provider->add($other, 'Other tenant secret about deployment postgres', []);
        foreach ($this->provider->search($other, 'deployment postgres migration', 10) as $hit) {
            $this->assertNotContains($hit->id, [$a, $b]);
        }
        // Foreign delete is refused (404 → treated as no-op) and does not remove the row.
        $this->provider->delete($other, $a);
        $this->assertCount(2, $this->provider->list($this->scope, 50));

        $this->provider->delete($this->scope, $a);
        $ids = array_map(fn ($m) => $m->id, $this->provider->list($this->scope, 50));
        $this->assertSame([$b], $ids);

        $this->assertSame(1, $this->provider->deleteAll($this->scope));
        $this->assertSame([], $this->provider->list($this->scope, 50));
    }
}
