<?php

namespace plugin\admin\app\service;

use RuntimeException;

class GameAssistUserAdminService
{
    public function sanitizeRows(array $rows): array
    {
        return array_map(static function ($row): array {
            if (is_array($row)) {
                $item = $row;
            } elseif (is_object($row) && method_exists($row, 'toArray')) {
                $item = $row->toArray();
            } else {
                $item = (array)$row;
            }
            unset($item['password_hash']);
            return $item;
        }, $rows);
    }

    public function filterStatusUpdate(array $data): array
    {
        if (!array_key_exists('status', $data)) {
            throw new RuntimeException('用户状态不能为空');
        }

        if (!in_array((string)$data['status'], ['0', '1'], true)) {
            throw new RuntimeException('用户状态值异常');
        }

        return ['status' => (int)$data['status']];
    }

    public function buildPasswordHash(string $password): string
    {
        if (mb_strlen($password) < 6) {
            throw new RuntimeException('密码至少需要6位');
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }
}
