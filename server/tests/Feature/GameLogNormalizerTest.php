<?php

namespace tests\Feature;

use app\service\GameLogNormalizer;
use PHPUnit\Framework\TestCase;

class GameLogNormalizerTest extends TestCase
{
    public function testEventTimeAcceptsMillisecondTimestamp(): void
    {
        $events = (new GameLogNormalizer())->normalizeEvents([[
            'module' => '订单',
            'title' => '居民订单',
            'time' => 1721200000123,
        ]]);

        $this->assertSame('2024-07-17 14:06:40', $events[0]['time']);
    }

    public function testEventTimeAcceptsSecondTimestamp(): void
    {
        $events = (new GameLogNormalizer())->normalizeEvents([[
            'module' => '订单',
            'title' => '居民订单',
            'time' => 1721200000,
        ]]);

        $this->assertSame('2024-07-17 14:06:40', $events[0]['time']);
    }

    public function testEventTimeKeepsDateStringEquivalent(): void
    {
        $events = (new GameLogNormalizer())->normalizeEvents([[
            'module' => '订单',
            'title' => '居民订单',
            'time' => '2026-07-17 12:00:00',
        ]]);

        $this->assertSame('2026-07-17 12:00:00', $events[0]['time']);
    }

    public function testInvalidEventTimeFallsBackToServerTime(): void
    {
        $before = time();
        $events = (new GameLogNormalizer())->normalizeEvents([[
            'module' => '订单',
            'title' => '居民订单',
            'time' => 'not-a-time',
        ]]);
        $after = time();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $events[0]['time']);
        $actual = strtotime($events[0]['time']);
        $this->assertNotFalse($actual);
        $this->assertGreaterThanOrEqual($before - 1, $actual);
        $this->assertLessThanOrEqual($after + 1, $actual);
    }

    public function testMissingEventTimeFallsBackToServerTime(): void
    {
        $before = time();
        $events = (new GameLogNormalizer())->normalizeEvents([[
            'module' => '订单',
            'title' => '居民订单',
        ]]);
        $after = time();

        $actual = strtotime($events[0]['time']);
        $this->assertNotFalse($actual);
        $this->assertGreaterThanOrEqual($before - 1, $actual);
        $this->assertLessThanOrEqual($after + 1, $actual);
    }

    public function testStructuredLogTimeAcceptsMillisecondTimestamp(): void
    {
        $line = (new GameLogNormalizer())->formatStructuredLog([
            'time' => 1721200000123,
            'level' => 'info',
            'category' => 'runtime',
            'message' => 'started',
        ]);

        $this->assertSame('2024-07-17 14:06:40 [INFO] [runtime] started', $line);
    }
}
