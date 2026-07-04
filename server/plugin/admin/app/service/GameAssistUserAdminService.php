<?php

namespace plugin\admin\app\service;

use app\support\I18n;
use RuntimeException;

class GameAssistUserAdminService
{
    public function __construct(private string $locale = I18n::DEFAULT_LOCALE)
    {
        $this->locale = I18n::normalizeLocale($this->locale);
    }

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
            throw new RuntimeException($this->t('admin.gameassist.status_empty'));
        }

        if (!in_array((string)$data['status'], ['0', '1'], true)) {
            throw new RuntimeException($this->t('admin.gameassist.status_invalid'));
        }

        return ['status' => (int)$data['status']];
    }

    public function buildPasswordHash(string $password): string
    {
        if (mb_strlen($password) < 6) {
            throw new RuntimeException($this->t('admin.gameassist.password_min_length'));
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    private function t(string $key): string
    {
        return I18n::t($key, [], $this->locale);
    }
}
