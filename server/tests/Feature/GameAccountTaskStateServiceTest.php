<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\process\GameAccountTaskStateWriter;
use app\service\GameAccountTaskStateService;
use PHPUnit\Framework\TestCase;
use stdClass;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameAccountTaskStatePendingStore;
use tests\Support\ArrayGameAccountTaskStateQueue;

class GameAccountTaskStateServiceTest extends TestCase
{
    public function testGetReturnsExplicitEmptyStateWhenNeverSaved(): void
    {
        $service = new GameAccountTaskStateService($this->repository(), 1024, pendingStore: new ArrayGameAccountTaskStatePendingStore());

        $result = $service->get(3);

        $this->assertFalse($result['exists']);
        $this->assertInstanceOf(stdClass::class, $result['state']);
        $this->assertNull($result['saved_at']);
        $this->assertSame(0, $result['bytes']);
    }

    public function testSaveQueuesTaskStateSnapshotAndWriterPersistsIt(): void
    {
        $repository = $this->repository();
        $queue = new ArrayGameAccountTaskStateQueue();
        $pending = new ArrayGameAccountTaskStatePendingStore();
        $service = new GameAccountTaskStateService($repository, 1024, $queue, $pending);

        $queueResult = $service->enqueueSave(3, (object)[
            'task' => 'plant',
            'progress' => 7,
            'flags' => (object)['done' => false],
        ]);

        $this->assertGreaterThan(0, $queueResult['bytes']);
        $this->assertSame(1, $queue->stats()['total_pending']);
        $this->assertTrue($service->get(3)['exists']);
        $this->assertSame('plant', $service->get(3)['state']->task);

        $writer = new GameAccountTaskStateWriter($queue, $repository, $service);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);
        $readResult = $service->get(3);

        $this->assertTrue($readResult['exists']);
        $this->assertSame('plant', $readResult['state']->task);
        $this->assertSame(7, $readResult['state']->progress);
        $this->assertFalse($readResult['state']->flags->done);
        $this->assertNull($pending->get(3));
    }

    public function testPendingSnapshotTakesPrecedenceOverPersistedStateAndHashClearIsStrict(): void
    {
        $repository = $this->repository();
        $queue = new ArrayGameAccountTaskStateQueue();
        $pending = new ArrayGameAccountTaskStatePendingStore();
        $service = new GameAccountTaskStateService($repository, 1024, $queue, $pending);
        $service->persistSnapshots([[
            'game_account_id' => 3,
            'state_json' => '{"step":1}',
            'state_hash' => hash('sha256', '{"step":1}'),
            'state_bytes' => strlen('{"step":1}'),
            'saved_at' => '2026-07-08 12:00:00',
        ]]);

        $service->enqueueSave(3, (object)['step' => 2]);
        $result = $service->get(3);

        $this->assertTrue($result['exists']);
        $this->assertSame(2, $result['state']->step);
        $service->clearPendingIfHashMatches(3, hash('sha256', '{"step":1}'));
        $this->assertSame(2, $service->get(3)['state']->step);

        $writer = new GameAccountTaskStateWriter($queue, $repository, $service);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $this->assertNull($pending->get(3));
        $this->assertSame(2, $service->get(3)['state']->step);
    }

    public function testPersistSnapshotsSkipsUnchangedState(): void
    {
        $repository = $this->repository();
        $service = new GameAccountTaskStateService($repository, 1024, pendingStore: new ArrayGameAccountTaskStatePendingStore());
        $json = '{"step":1}';
        $snapshot = [
            'game_account_id' => 3,
            'state_json' => $json,
            'state_hash' => hash('sha256', $json),
            'state_bytes' => strlen($json),
            'saved_at' => '2026-07-08 12:00:00',
        ];

        $first = $service->persistSnapshots([$snapshot]);
        $second = $service->persistSnapshots([$snapshot]);

        $this->assertSame(1, $first['saved']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertSame(0, $second['saved']);
        $this->assertSame(1, $second['unchanged']);
    }

    public function testSaveRejectsOversizedState(): void
    {
        $service = new GameAccountTaskStateService($this->repository(), 16, new ArrayGameAccountTaskStateQueue(), new ArrayGameAccountTaskStatePendingStore());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('task state too large');

        $service->enqueueSave(3, (object)['payload' => str_repeat('x', 32)]);
    }

    public function testSaveRejectsDeletedAccountAndDoesNotCreateOrphanState(): void
    {
        $repository = $this->repository();
        $service = new GameAccountTaskStateService($repository, 1024, new ArrayGameAccountTaskStateQueue(), new ArrayGameAccountTaskStatePendingStore());

        $repository->deleteForUser(7, 3);

        try {
            $service->enqueueSave(3, (object)['step' => 1]);
            $this->fail('Expected deleted account to be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('game account not found', $exception->getMessage());
            $this->assertNull($repository->taskState(3));
        }
    }

    public function testDeletingAccountClearsTaskState(): void
    {
        $repository = $this->repository();
        $service = new GameAccountTaskStateService($repository, 1024, pendingStore: new ArrayGameAccountTaskStatePendingStore());

        $service->persistSnapshots([[
            'game_account_id' => 3,
            'state_json' => '{"step":1}',
            'state_hash' => hash('sha256', '{"step":1}'),
            'state_bytes' => strlen('{"step":1}'),
            'saved_at' => '2026-07-08 12:00:00',
        ]]);
        $this->assertNotNull($repository->taskState(3));

        $repository->deleteForUser(7, 3);

        $this->assertNull($repository->taskState(3));
    }

    private function repository(): ArrayGameAccountRepository
    {
        return new ArrayGameAccountRepository([
            [
                'id' => 3,
                'user_id' => 7,
                'display_name' => 'any-player',
                'game_username' => 'any-player',
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => '',
                'log_session_id' => 'session-1',
                'remark' => '',
                'config_json' => '{}',
            ],
        ]);
    }
}
