<?php

namespace app\repository;

interface AnnouncementRepositoryInterface
{
    public function latestEnabled(): ?array;
}
