<?php

namespace app\service;

use InvalidArgumentException;

class GameAccountResourceService
{
    private const FIELDS = [
        'level',
        'water',
        'diamond',
        'coin',
        'speedCard',
        'hireBook',
        'pearl',
        'floralCoin',
        'meowCoin',
        'raceCoin',
        'flowerFinish',
        'satinFinish',
        'decorateFinish',
        'customerFinish',
    ];

    public function __construct(private ?GameAccountRuntimeResourceStoreInterface $store = null)
    {
        $this->store ??= new RedisGameAccountRuntimeResourceStore();
    }

    public function resourcesForAccount(int $accountId): array
    {
        $snapshot = $this->store->get($accountId);
        if (!$snapshot) {
            return self::defaultResources();
        }
        return array_merge(self::defaultResources(), $snapshot['resources']);
    }

    public function saveStatusPayload(int $accountId, array $payload): array
    {
        $parsed = self::normalizeStatusPayload($payload);
        $saved = $this->store->save($accountId, $parsed['resources']);

        return [
            'resources' => array_merge(self::defaultResources(), $parsed['resources']),
            'saved_at' => (string)($saved['updated_at'] ?? ''),
            'unknown_keys' => $parsed['unknown_keys'],
        ];
    }

    public function clear(int $accountId): void
    {
        $this->store->clear($accountId);
    }

    public static function defaultResources(): array
    {
        return [
            'level' => 0,
            'water' => 0,
            'diamond' => 0,
            'coin' => 0,
            'speedCard' => 0,
            'hireBook' => 0,
            'pearl' => 0,
            'floralCoin' => 0,
            'meowCoin' => 0,
            'raceCoin' => '0/0',
            'flowerFinish' => 0,
            'satinFinish' => 0,
            'decorateFinish' => 0,
            'customerFinish' => 0,
        ];
    }

    public static function normalizeStatusPayload(array $payload): array
    {
        if (array_key_exists('account_id', $payload)) {
            throw new InvalidArgumentException('account_id is not accepted in status messages');
        }

        $source = $payload['resources'] ?? $payload;
        if (!is_array($source)) {
            throw new InvalidArgumentException('status resources must be an object');
        }
        if (array_key_exists('account_id', $source)) {
            throw new InvalidArgumentException('account_id is not accepted in status messages');
        }

        unset($source['type'], $source['request_id'], $source['session_id'], $source['script_version']);
        if (array_key_exists('gold', $source)) {
            if (array_key_exists('coin', $source) && (string)$source['coin'] !== (string)$source['gold']) {
                throw new InvalidArgumentException('status fields coin and gold conflict');
            }
            $source['coin'] = $source['gold'];
            unset($source['gold']);
        }

        $resources = [];
        $unknownKeys = [];
        $allowed = array_flip(self::FIELDS);
        foreach ($source as $key => $value) {
            $key = (string)$key;
            if (!isset($allowed[$key])) {
                $unknownKeys[] = $key;
                continue;
            }
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException("status field {$key} must be scalar");
            }
            if ($value === null || $value === '') {
                continue;
            }
            $resources[$key] = $value;
        }

        return [
            'resources' => $resources,
            'unknown_keys' => $unknownKeys,
        ];
    }
}
