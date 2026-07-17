<?php

namespace tests\Feature;

use app\process\GameLogWriter;
use app\service\GameLogQueue;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;
use tests\Support\ArrayGameLogQueue;

class GameLogWriterTest extends TestCase
{
    public function testAccountAlwaysRoutesToSameShard(): void
    {
        $this->assertSame(3, GameLogQueue::shardForAccount(3));
        $this->assertSame(GameLogQueue::shardForAccount(67), GameLogQueue::shardForAccount(3));
        $this->assertSame(63, GameLogQueue::shardForAccount(-63));
    }

    public function testDifferentWorkersConsumeOnlyOwnedShards(): void
    {
        $queue = new ArrayGameLogQueue();
        $repository = new ArrayGameAccountRepository([]);
        $queue->enqueueNormal(3, ['account 3 line'], 'session-3');
        $queue->enqueueNormal(4, ['account 4 line'], 'session-4');

        $writer0 = new GameLogWriter($queue, $repository);
        $writer0->setWorkerIdentity(0, 2);
        $writer0->drainQueues();
        $writer0->flushDueBuffers(true);

        $this->assertSame(0, $repository->countNormalLogLines(3, 'session-3'));
        $this->assertSame(1, $repository->countNormalLogLines(4, 'session-4'));

        $writer1 = new GameLogWriter($queue, $repository);
        $writer1->setWorkerIdentity(1, 2);
        $writer1->drainQueues();
        $writer1->flushDueBuffers(true);

        $this->assertSame(1, $repository->countNormalLogLines(3, 'session-3'));
        $this->assertSame(1, $repository->countNormalLogLines(4, 'session-4'));
    }

    public function testWriterFlushesNormalLogsInBatchAndKeepsLatest2500Lines(): void
    {
        $queue = new ArrayGameLogQueue();
        $repository = new ArrayGameAccountRepository([]);
        $lines = [];
        for ($i = 1; $i <= 2502; $i++) {
            $lines[] = "line {$i}";
        }
        $queue->enqueueNormal(3, $lines, 'session-1');

        $writer = new GameLogWriter($queue, $repository);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $stored = $repository->listNormalLogLines(3, 'session-1', 0, 2500);
        $this->assertSame(2500, $repository->countNormalLogLines(3, 'session-1'));
        $this->assertSame('line 3', $stored[0]['message']);
        $this->assertSame('line 2502', $stored[2499]['message']);
    }

    public function testWriterExtractsEventHistoryFromNormalLogLines(): void
    {
        $queue = new ArrayGameLogQueue();
        $repository = new ArrayGameAccountRepository([]);
        $queue->enqueueNormal(3, [
            'normal line [[EVT]] {"module":"订单","title":"居民订单","message":"完成1单","status":"ok"}',
        ], 'session-1');

        $writer = new GameLogWriter($queue, $repository);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $events = $repository->listEventLogs(3, 0, 2500);
        $this->assertCount(1, $events);
        $this->assertSame('订单', $events[0]['module']);
        $this->assertSame('居民订单', $events[0]['title']);
        $this->assertSame('完成1单', $events[0]['desc']);
    }

    public function testWriterNormalizesEventTimeFromMillisecondTimestamp(): void
    {
        $queue = new ArrayGameLogQueue();
        $repository = new ArrayGameAccountRepository([]);
        $queue->enqueueEvents(3, [[
            'module' => '订单',
            'title' => '居民订单',
            'message' => '完成1单',
            'time' => 1721200000123,
        ]]);

        $writer = new GameLogWriter($queue, $repository);
        $writer->drainQueues();
        $writer->flushDueBuffers(true);

        $events = $repository->listEventLogs(3, 0, 2500);
        $this->assertCount(1, $events);
        $this->assertSame('2024-07-17 14:06:40', $events[0]['time']);
    }

    public function testClearingNormalLogsDoesNotClearEventHistory(): void
    {
        $repository = new ArrayGameAccountRepository([]);
        $repository->appendNormalLogLines(3, 'session-1', ['line 1'], 2500);
        $repository->appendEventLogs(3, [['module' => '订单', 'title' => '居民订单']], 2500);

        $repository->clearNormalLogLines(3, null);

        $this->assertSame(0, $repository->countNormalLogLines(3, 'session-1'));
        $this->assertSame(1, $repository->countEventLogs(3));
    }
}
