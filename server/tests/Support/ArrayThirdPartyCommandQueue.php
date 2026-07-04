<?php

namespace tests\Support;

use app\service\ThirdPartyCommandQueueInterface;

class ArrayThirdPartyCommandQueue implements ThirdPartyCommandQueueInterface
{
    public array $commands = [];
    public array $states = [];
    public array $slotStates = [];

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
        return array_shift($this->commands) ?: null;
    }

    public function writeAccountState(int $accountId, array $state, int $ttl = 120): void
    {
        $this->states[$accountId] = $state + ['ttl' => $ttl];
    }

    public function clearAccountState(int $accountId): void
    {
        unset($this->states[$accountId]);
    }

    public function writeSlotState(string $slotId, array $state, int $ttl = 120): void
    {
        $this->slotStates[$slotId] = $state + ['ttl' => $ttl];
    }

    public function readSlotState(string $slotId): ?array
    {
        return $this->slotStates[$slotId] ?? null;
    }

    public function clearSlotState(string $slotId): void
    {
        unset($this->slotStates[$slotId]);
    }

    private function push(string $action, int $accountId): array
    {
        $command = [
            'command_id' => sprintf('test-%s-%d-%d', $action, $accountId, count($this->commands) + 1),
            'account_id' => $accountId,
            'action' => $action,
            'requested_at' => '2026-07-03 00:00:00',
        ];
        $this->commands[] = $command;
        return $command;
    }

    private function pushSlot(string $action, string $slotId, array $extra = []): array
    {
        $command = [
            'command_id' => sprintf('test-%s-%s-%d', $action, $slotId, count($this->commands) + 1),
            'slot_id' => $slotId,
            'action' => $action,
            'requested_at' => '2026-07-03 00:00:00',
        ] + $extra;
        $this->commands[] = $command;
        return $command;
    }

    private function pushGlobal(string $action, array $extra = []): array
    {
        $command = [
            'command_id' => sprintf('test-%s-%d', $action, count($this->commands) + 1),
            'action' => $action,
            'requested_at' => '2026-07-03 00:00:00',
        ] + $extra;
        $this->commands[] = $command;
        return $command;
    }
}
