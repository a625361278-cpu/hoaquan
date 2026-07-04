<?php

namespace app\service;

interface ThirdPartyCommandQueueInterface
{
    public function enqueueStart(int $accountId): array;

    public function enqueueStop(int $accountId): array;

    public function enqueueStartSlot(string $slotId): array;

    public function enqueueStopSlot(string $slotId, bool $force = true): array;

    public function enqueueStartAllSlots(): array;

    public function enqueueStopAllSlots(bool $force = true): array;

    public function popCommand(): ?array;

    public function writeAccountState(int $accountId, array $state, int $ttl = 120): void;

    public function clearAccountState(int $accountId): void;

    public function writeSlotState(string $slotId, array $state, int $ttl = 120): void;

    public function readSlotState(string $slotId): ?array;

    public function clearSlotState(string $slotId): void;
}
