<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
    }

    public function test_deletes_only_expired_rows_and_live_keys_still_read(): void
    {
        $prefix = config('cache.prefix');
        DB::table('cache')->insert([
            ['key' => $prefix.'old1', 'value' => serialize('a'), 'expiration' => time() - 3600],
            ['key' => $prefix.'old2', 'value' => serialize('b'), 'expiration' => time() - 1],
        ]);
        Cache::put('live', 'still here', 600);
        Cache::forever('forever', 'kept');

        $this->artisan('cache:prune-expired', ['--batch' => 1])->assertSuccessful();

        $this->assertSame(2, DB::table('cache')->count());
        $this->assertSame('still here', Cache::get('live'));
        $this->assertSame('kept', Cache::get('forever'));
    }

    public function test_noop_when_cache_store_is_not_database(): void
    {
        config(['cache.default' => 'array']);
        DB::table('cache')->insert(['key' => 'x', 'value' => serialize(1), 'expiration' => time() - 10]);

        $this->artisan('cache:prune-expired')->assertSuccessful();

        $this->assertSame(1, DB::table('cache')->count());
    }
}
