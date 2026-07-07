<?php

namespace app\service;

interface GameLogSinkInterface
{
    public function enqueueNormal(int $accountId, array $lines, string $sessionId = ''): void;

    public function enqueueEvents(int $accountId, array $events): void;
}
