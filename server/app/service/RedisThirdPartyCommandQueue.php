<?php

namespace app\service;

use support\Redis;

class RedisThirdPartyCommandQueue implements ThirdPartyCommandQueueInterface
{
    public const COMMAND_KEY = 'gameassist:third_party_ws:commands';
    public const ACCOUNT_STATE_PREFIX = 'gameassist:third_party_ws:accounts:';
    public const SLOT_STATE_PREFIX = 'gameassist:third_party_ws:slots:';

    public function enqueueStart(int $accountId): array
    {
        return $this->push('start', $accountId);
    }

    public function enqueueStop(int $accountId): array
    {
        return $this->push('stop', $accountId);
    }

    public function enqueueStartSlot(string $slotId): array
    {
        return $this->pushSlot('start_slot', $slotId);
    }

    public function enqueueStopSlot(string $slotId, bool $force = true): array
    {
        return $this->pushSlot('stop_slot', $slotId, ['force' => $force]);
    }

    public function enqueueStartAllSlots(): array
    {
        return $this->pushGlobal('start_all_slots');
    }

    public function enqueueStopAllSlots(bool $force = true): array
    {
        return $this->pushGlobal('stop_all_slots', ['force' => $force]);
    }

    public function popCommand(): ?array
    {
        $payload = Redis::lPop(self::COMMAND_KEY);
        if (!$payload) {
            return null;
        }

        $command = json_decode((string)$payload, true);
        return is_array($command) ? $command : null;
    }

    public function writeAccountState(int $accountId, array $state, int $ttl = 120): void
    {
        Redis::setEx(self::ACCOUNT_STATE_PREFIX . $accountId, $ttl, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function clearAccountState(int $accountId): void
    {
        Redis::del(self::ACCOUNT_STATE_PREFIX . $accountId);
    }

    public function writeSlotState(string $slotId, array $state, int $ttl = 120): void
    {
        Redis::setEx(self::SLOT_STATE_PREFIX . $slotId, $ttl, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function readSlotState(string $slotId): ?array
    {
        $payload = Redis::get(self::SLOT_STATE_PREFIX . $slotId);
        if (!$payload) {
            return null;
        }
        $state = json_decode((string)$payload, true);
        return is_array($state) ? $state : null;
    }

    public function clearSlotState(string $slotId): void
    {
        Redis::del(self::SLOT_STATE_PREFIX . $slotId);
    }

    private function push(string $action, int $accountId): array
    {
        $command = [
            'command_id' => bin2hex(random_bytes(16)),
            'account_id' => $accountId,
            'action' => $action,
            'requested_at' => date('Y-m-d H:i:s'),
        ];
        Redis::rPush(self::COMMAND_KEY, json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $command;
    }

    private function pushSlot(string $action, string $slotId, array $extra = []): array
    {
        $command = [
            'command_id' => bin2hex(random_bytes(16)),
            'slot_id' => $slotId,
            'action' => $action,
            'requested_at' => date('Y-m-d H:i:s'),
        ] + $extra;
        Redis::rPush(self::COMMAND_KEY, json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $command;
    }

    private function pushGlobal(string $action, array $extra = []): array
    {
        $command = [
            'command_id' => bin2hex(random_bytes(16)),
            'action' => $action,
            'requested_at' => date('Y-m-d H:i:s'),
        ] + $extra;
        Redis::rPush(self::COMMAND_KEY, json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $command;
    }
}
