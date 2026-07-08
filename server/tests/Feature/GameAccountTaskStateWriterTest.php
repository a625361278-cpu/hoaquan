<?php

namespace tests\Feature;

use app\process\GameAccountTaskStateWriter;
use app\service\GameAccountTaskStateQueue;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameAccountTaskStateQueue;

class GameAccountTaskStateWriterTest extends TestCase
{
    public function testAccountAlwaysRoutesToSameShard(): void
    {
        $this->assertSame(3, GameAccountTaskStateQueue::shardForAccount(3));
        $this->assertSame(GameAccountTaskStateQueue::shardForAccount(67), GameAccountTaskStateQueue::shardForAccount(3));
        $this->assertSame(63, GameAccountTaskStateQueue::shardForAccount(-63));
    }

    public function testWriterConsumesOnlyOwnedShards(): void
    {
        $queue = new ArrayGameAccountTaskStateQueue();
        $repository = $this->repository([3, 4]);
        $this->enqueueState($queue, 3, ['step' => 3]);
        $this->enqueueState($queue, 4, ['step' => 4]);

        $writer0 = new GameAccountTaskStateWriter($queue, $repository);
        $writer0->setWorkerIdentity(0, 2);
        $writer0->drainQueues();
        $writer0->flushDueBuffers(true);

        $this->assertNull($repository->taskState(3));
        $this->assertSame(['step' => 4], json_decode($repository->taskState(4)['state_json'], true));

        $writer1 = new GameAccountTaskStateWriter($queue, $repository);
        $writer1->setWorkerIdentity(1, 2);
        $writer1->drainQueues();
        $writer1->flushDueBuffers(true);

        $this->assertSame(['step' => 3], json_decode($repository->taskState(3)['state_json'], true));
    }

    public function testWriterKeepsLatestSnapshotForSameAccount(): void
    {
        $queue = new ArrayGameAccountTaskStateQueue();
        $repository = $this->repository([3]);
        $this->enqueueState($queue, 3, ['step' => 1]);
        $this->enqueueState($queue, 3, ['step' => 2]);

        $writer = new GameAccountTaskStateWriter($queue, $repository);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $this->assertSame(['step' => 2], json_decode($repository->taskState(3)['state_json'], true));
    }

    public function testWriterSkipsDeletedAccountWithoutCreatingOrphanState(): void
    {
        $queue = new ArrayGameAccountTaskStateQueue();
        $repository = $this->repository([3]);
        $this->enqueueState($queue, 3, ['step' => 1]);
        $repository->deleteForUser(7, 3);

        $writer = new GameAccountTaskStateWriter($queue, $repository);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $this->assertNull($repository->taskState(3));
    }

    private function enqueueState(ArrayGameAccountTaskStateQueue $queue, int $accountId, array $state): void
    {
        $json = json_encode((object)$state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $queue->enqueue($accountId, $json, hash('sha256', $json), strlen($json), '2026-07-08 12:00:00');
    }

    private function repository(array $ids): ArrayGameAccountRepository
    {
        $accounts = [];
        foreach ($ids as $id) {
            $accounts[] = [
                'id' => $id,
                'user_id' => 7,
                'display_name' => 'player-' . $id,
                'game_username' => 'player-' . $id,
                'channel_code' => 'official_app',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'sync_status' => 'synced',
                'third_party_account_id' => '',
                'log_session_id' => 'session-1',
                'remark' => '',
                'config_json' => '{}',
            ];
        }
        return new ArrayGameAccountRepository($accounts);
    }
}
