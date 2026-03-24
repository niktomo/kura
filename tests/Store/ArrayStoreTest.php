<?php

namespace Kura\Tests\Store;

use Kura\Store\ArrayStore;
use PHPUnit\Framework\TestCase;

class ArrayStoreTest extends TestCase
{
    private ArrayStore $store;

    private string $version = 'v1';

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    // -------------------------------------------------------------------------
    // IDs
    // -------------------------------------------------------------------------

    public function test_get_ids_returns_false_when_not_stored(): void
    {
        $this->assertFalse(
            $this->store->getPks('users', $this->version),
            'getIds should return false when no ids have been stored for the table',
        );
    }

    public function test_get_ids_returns_stored_ids(): void
    {
        $ids = [1, 2, 3];
        $this->store->putPks('users', $this->version, $ids, 3600);

        $this->assertSame(
            $ids,
            $this->store->getPks('users', $this->version),
            'getIds should return the exact ids list that was stored',
        );
    }

    public function test_ids_are_isolated_per_table(): void
    {
        $this->store->putPks('users', $this->version, [1, 2], 3600);
        $this->store->putPks('products', $this->version, [10, 20], 3600);

        $this->assertSame(
            [1, 2],
            $this->store->getPks('users', $this->version),
            'Users table ids should be isolated from products',
        );
        $this->assertSame(
            [10, 20],
            $this->store->getPks('products', $this->version),
            'Products table ids should be isolated from users',
        );
    }

    // -------------------------------------------------------------------------
    // Records
    // -------------------------------------------------------------------------

    public function test_get_record_returns_false_when_not_stored(): void
    {
        $this->assertFalse(
            $this->store->getRecord('users', $this->version, 1),
            'getRecord should return false when record has not been stored',
        );
    }

    public function test_get_record_returns_stored_record(): void
    {
        $record = ['id' => 1, 'name' => 'Alice'];
        $this->store->putRecord('users', $this->version, 1, $record, 3600);

        $this->assertSame(
            $record,
            $this->store->getRecord('users', $this->version, 1),
            'getRecord should return the exact record that was stored',
        );
    }

    public function test_records_are_isolated_per_table(): void
    {
        $this->store->putRecord('users', $this->version, 1, ['id' => 1, 'name' => 'Alice'], 3600);
        $this->store->putRecord('products', $this->version, 1, ['id' => 1, 'name' => 'Widget'], 3600);

        $aliceRecord = $this->store->getRecord('users', $this->version, 1);
        $widgetRecord = $this->store->getRecord('products', $this->version, 1);
        assert(is_array($aliceRecord) && is_array($widgetRecord));
        $this->assertSame(
            'Alice',
            $aliceRecord['name'],
            'User record should contain Alice, not Widget',
        );
        $this->assertSame(
            'Widget',
            $widgetRecord['name'],
            'Product record should contain Widget, not Alice',
        );
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_get_index_returns_false_when_not_stored(): void
    {
        $this->assertFalse(
            $this->store->getIndex('users', $this->version, 'status'),
            'getIndex should return false when no index has been stored for the column',
        );
    }

    public function test_get_index_returns_stored_entries(): void
    {
        /** @var list<array{mixed, list<int|string>}> $entries */
        $entries = [['active', [1, 2, 3]], ['inactive', [4]]];
        $this->store->putIndex('users', $this->version, 'status', $entries, 3600);

        $this->assertSame(
            $entries,
            $this->store->getIndex('users', $this->version, 'status'),
            'getIndex should return the exact entries that were stored',
        );
    }

    // -------------------------------------------------------------------------
    // Lock
    // -------------------------------------------------------------------------

    public function test_is_locked_returns_false_when_not_locked(): void
    {
        $this->assertFalse(
            $this->store->isLocked('users'),
            'isLocked should return false when no lock has been acquired',
        );
    }

    public function test_acquire_lock_returns_true_on_first_call(): void
    {
        $this->assertTrue(
            $this->store->acquireLock('users', 30),
            'acquireLock should return true when no lock exists',
        );
    }

    public function test_acquire_lock_returns_false_when_already_locked(): void
    {
        $this->store->acquireLock('users', 30);

        $this->assertFalse(
            $this->store->acquireLock('users', 30),
            'acquireLock should return false when the table is already locked',
        );
    }

    public function test_is_locked_returns_true_after_acquire(): void
    {
        $this->store->acquireLock('users', 30);

        $this->assertTrue(
            $this->store->isLocked('users'),
            'isLocked should return true after acquiring a lock',
        );
    }

    public function test_release_lock_allows_reacquire(): void
    {
        $this->store->acquireLock('users', 30);
        $this->store->releaseLock('users');

        $this->assertTrue(
            $this->store->acquireLock('users', 30),
            'acquireLock should succeed after releasing the lock',
        );
    }

    // -------------------------------------------------------------------------
    // Flush
    // -------------------------------------------------------------------------

    public function test_flush_removes_all_data_for_table_and_version(): void
    {
        $this->store->putPks('users', $this->version, [1, 2], 3600);
        $this->store->putRecord('users', $this->version, 1, ['id' => 1], 3600);
        /** @var list<array{mixed, list<int|string>}> $entries */
        $entries = [['active', [1]]];
        $this->store->putIndex('users', $this->version, 'status', $entries, 3600);

        $this->store->flush('users', $this->version);

        $this->assertFalse(
            $this->store->getPks('users', $this->version),
            'Ids should be removed after flush',
        );
        $this->assertFalse(
            $this->store->getRecord('users', $this->version, 1),
            'Records should be removed after flush',
        );
        $this->assertFalse(
            $this->store->getIndex('users', $this->version, 'status'),
            'Indexes should be removed after flush',
        );
    }

    public function test_flush_does_not_affect_other_tables(): void
    {
        $this->store->putPks('users', $this->version, [1], 3600);
        $this->store->putPks('products', $this->version, [10], 3600);

        $this->store->flush('users', $this->version);

        $this->assertSame(
            [10],
            $this->store->getPks('products', $this->version),
            'Flushing users should not affect products',
        );
    }

    public function test_flush_does_not_affect_other_versions(): void
    {
        $this->store->putPks('users', 'v1', [1], 3600);
        $this->store->putPks('users', 'v2', [2], 3600);

        $this->store->flush('users', 'v1');

        $this->assertFalse(
            $this->store->getPks('users', 'v1'),
            'v1 ids should be removed after flush',
        );
        $this->assertSame(
            [2],
            $this->store->getPks('users', 'v2'),
            'v2 ids should not be affected by flushing v1',
        );
    }
}
