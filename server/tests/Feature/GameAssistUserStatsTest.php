<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameAssistUserStats;

class GameAssistUserStatsTest extends TestCase
{
    public function testCountsRegistrationStatsFromGameAssistUsers(): void
    {
        $stats = new GameAssistUserStats([
            ['created_at' => '2026-07-01 01:00:00'],
            ['created_at' => '2026-06-30 12:00:00'],
            ['created_at' => '2026-06-25 12:00:00'],
            ['created_at' => '2026-06-01 12:00:00'],
        ]);

        $result = $stats->summarize(strtotime('2026-07-01 15:00:00'));

        $this->assertSame(1, $result['today_user_count']);
        $this->assertSame(3, $result['day7_user_count']);
        $this->assertSame(3, $result['day30_user_count']);
        $this->assertSame(4, $result['user_count']);
        $this->assertSame(1, $result['day7_detail']['07-01']);
        $this->assertSame(1, $result['day7_detail']['06-30']);
        $this->assertSame(1, $result['day7_detail']['06-25']);
    }
}
