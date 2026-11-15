<?php

namespace Tests\Unit;

use App\Memory\Mem0MemoryProvider;
use App\Memory\MemoryUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Verifies the Laravel adapter speaks exactly the sidecar contract defined in docker/mem0/app.py. */
class Mem0MemoryProviderContractTest extends TestCase
{
    private const SCOPE = 'companion:user:7';

    protected function setUp(): void
    {
        parent::setUp();
        config(['companion.memory.mem0.base_url' => 'http://mem0.test', 'companion.memory.mem0.api_key' => 'k-0123456789abcdef0123456789']);
    }

    public function test_add_sends_scope_text_metadata_with_bearer_auth(): void
    {
        Http::fake(['mem0.test/v1/memories' => Http::response(['id' => 'mem-1'])]);
        $id = (new Mem0MemoryProvider)->add(self::SCOPE, 'The user likes tea.', ['local_id' => 5, 'category' => 'preference']);
        $this->assertSame('mem-1', $id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'http://mem0.test/v1/memories'
            && $r->hasHeader('Authorization', 'Bearer k-0123456789abcdef0123456789')
            && $r['scope'] === self::SCOPE && $r['text'] === 'The user likes tea.' && ((array) $r['metadata'])['local_id'] === 5);
    }

    public function test_search_maps_results_and_drops_foreign_scopes(): void
    {
        Http::fake(['mem0.test/v1/memories/search' => Http::response(['results' => [
            ['id' => 'a', 'memory' => 'Fact A', 'score' => 0.82, 'metadata' => ['local_id' => 1], 'created_at' => '2026-10-01T10:00:00+00:00', 'scope' => self::SCOPE],
            ['id' => 'b', 'memory' => 'Other user fact', 'score' => 0.99, 'scope' => 'companion:user:8'],
            ['bogus' => true],
        ]])]);
        $hits = (new Mem0MemoryProvider)->search(self::SCOPE, 'query', 5);
        $this->assertCount(1, $hits);
        $this->assertSame('a', $hits[0]->id);
        $this->assertSame(0.82, $hits[0]->score);
        Http::assertSent(fn (Request $r) => $r['limit'] === 5 && $r['scope'] === self::SCOPE && isset($r['threshold']));
    }

    public function test_delete_tolerates_404_and_delete_all_returns_count(): void
    {
        Http::fake([
            'mem0.test/v1/memories/missing*' => Http::response(['detail' => 'not found'], 404),
            'mem0.test/v1/scopes/*' => Http::response(['deleted' => 3]),
        ]);
        (new Mem0MemoryProvider)->delete(self::SCOPE, 'missing');
        $this->assertSame(3, (new Mem0MemoryProvider)->deleteAll(self::SCOPE));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'scope=companion%3Auser%3A7'));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://mem0.test/v1/scopes/companion%3Auser%3A7/memories');
    }

    public function test_errors_and_outages_raise_unavailable(): void
    {
        Http::fake(['mem0.test/*' => Http::response('boom', 500)]);
        $this->expectException(MemoryUnavailableException::class);
        (new Mem0MemoryProvider)->add(self::SCOPE, 'text here', []);
    }

    public function test_connection_failure_raises_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $this->expectException(MemoryUnavailableException::class);
        (new Mem0MemoryProvider)->search(self::SCOPE, 'q', 3);
    }

    public function test_missing_api_key_fails_closed(): void
    {
        config(['companion.memory.mem0.api_key' => '']);
        Http::fake();
        $this->expectException(MemoryUnavailableException::class);
        (new Mem0MemoryProvider)->list(self::SCOPE, 10);
    }
}
