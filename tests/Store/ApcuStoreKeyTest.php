<?php

namespace Kura\Tests\Store;

use Kura\Store\ApcuStore;
use PHPUnit\Framework\TestCase;

/**
 * Key generation logic only — no APCu calls, no Docker needed.
 */
class ApcuStoreKeyTest extends TestCase
{
    private ApcuStore $store;

    protected function setUp(): void
    {
        $this->store = new ApcuStore(prefix: 'kura');
    }

    public function test_ids_key(): void
    {
        $this->assertSame(
            'kura:users:v1:pks',
            $this->store->pksKey('users', 'v1'),
            'ids key should include prefix, table, and version',
        );
    }

    public function test_record_key_with_integer_id(): void
    {
        $this->assertSame(
            'kura:users:v1:record:1',
            $this->store->recordKey('users', 'v1', 1),
            'record key should include prefix, table, version, and integer id',
        );
    }

    public function test_record_key_with_string_id(): void
    {
        $this->assertSame(
            'kura:users:v1:record:abc',
            $this->store->recordKey('users', 'v1', 'abc'),
            'record key should include prefix, table, version, and string id',
        );
    }

    public function test_index_key_single_column(): void
    {
        $this->assertSame(
            'kura:users:v1:idx:status',
            $this->store->indexKey('users', 'v1', 'status'),
            'index key for single column should include prefix, table, version, and column',
        );
    }

    public function test_lock_key_has_no_version(): void
    {
        $this->assertSame(
            'kura:users:lock',
            $this->store->lockKey('users'),
            'lock key should not include version',
        );
    }
}
