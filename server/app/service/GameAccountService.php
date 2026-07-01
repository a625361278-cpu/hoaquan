<?php

namespace app\service;

use app\exception\ApiException;
use app\repository\GameAccountRepositoryInterface;
use app\support\ApiResponse;

class GameAccountService
{
    public function __construct(private GameAccountRepositoryInterface $accounts)
    {
    }

    public function listForUser(int $userId): array
    {
        return ApiResponse::success([
            'items' => array_map([$this, 'publicAccount'], $this->accounts->listByUserId($userId)),
            'empty_text' => '未添加游戏账号',
        ]);
    }

    public function createPlaceholder(): never
    {
        throw new ApiException('当前游戏接入未开放，暂不能添加游戏账号', 409);
    }

    private function publicAccount(array $account): array
    {
        return [
            'id' => (int)$account['id'],
            'display_name' => (string)$account['display_name'],
            'status' => (string)$account['status'],
            'remark' => (string)($account['remark'] ?? ''),
            'created_at' => $account['created_at'] ?? null,
        ];
    }
}
