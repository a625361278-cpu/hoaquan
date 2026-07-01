<?php

namespace plugin\admin\app\service;

use plugin\admin\app\model\GameAssistUser;

class GameAssistUserStats
{
    public function __construct(private ?array $rows = null)
    {
    }

    public function summarize(?int $now = null): array
    {
        $now ??= time();
        $todayStart = strtotime(date('Y-m-d 00:00:00', $now));
        $day7Start = $now - 7 * 24 * 60 * 60;
        $day30Start = $now - 30 * 24 * 60 * 60;

        if ($this->rows !== null) {
            return $this->summarizeRows($todayStart, $day7Start, $day30Start, $now);
        }

        return $this->summarizeDatabase($todayStart, $day7Start, $day30Start, $now);
    }

    private function summarizeRows(int $todayStart, int $day7Start, int $day30Start, int $now): array
    {
        $day7Detail = $this->emptyDay7Detail($now);
        $todayCount = 0;
        $day7Count = 0;
        $day30Count = 0;

        foreach ($this->rows as $row) {
            $createdAt = strtotime((string)($row['created_at'] ?? ''));
            if (!$createdAt) {
                continue;
            }
            if ($createdAt > $todayStart) {
                $todayCount++;
            }
            if ($createdAt > $day7Start) {
                $day7Count++;
            }
            if ($createdAt > $day30Start) {
                $day30Count++;
            }

            $key = date('m-d', $createdAt);
            if (array_key_exists($key, $day7Detail)) {
                $day7Detail[$key]++;
            }
        }

        return [
            'today_user_count' => $todayCount,
            'day7_user_count' => $day7Count,
            'day30_user_count' => $day30Count,
            'user_count' => count($this->rows),
            'day7_detail' => $day7Detail,
        ];
    }

    private function summarizeDatabase(int $todayStart, int $day7Start, int $day30Start, int $now): array
    {
        $today = date('Y-m-d H:i:s', $todayStart);
        $day7 = date('Y-m-d H:i:s', $day7Start);
        $day30 = date('Y-m-d H:i:s', $day30Start);

        $day7Detail = [];
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', $now - 24 * 60 * 60 * $i);
            $day7Detail[substr($date, 5)] = GameAssistUser::where('created_at', '>', "$date 00:00:00")
                ->where('created_at', '<', "$date 23:59:59")
                ->count();
        }

        return [
            'today_user_count' => GameAssistUser::where('created_at', '>', $today)->count(),
            'day7_user_count' => GameAssistUser::where('created_at', '>', $day7)->count(),
            'day30_user_count' => GameAssistUser::where('created_at', '>', $day30)->count(),
            'user_count' => GameAssistUser::count(),
            'day7_detail' => array_reverse($day7Detail),
        ];
    }

    private function emptyDay7Detail(int $now): array
    {
        $detail = [];
        for ($i = 6; $i >= 0; $i--) {
            $detail[date('m-d', $now - 24 * 60 * 60 * $i)] = 0;
        }
        return $detail;
    }
}
