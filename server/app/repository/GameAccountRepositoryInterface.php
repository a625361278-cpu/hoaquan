<?php

namespace app\repository;

interface GameAccountRepositoryInterface
{
    public function listByUserId(int $userId): array;
}
