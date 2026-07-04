<?php

namespace app\repository;

use support\Db;

class DbAnnouncementRepository implements AnnouncementRepositoryInterface
{
    public function latestEnabled(): ?array
    {
        $row = Db::table('ga_announcements')
            ->where('status', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        return $row ? (array)$row : null;
    }
}
