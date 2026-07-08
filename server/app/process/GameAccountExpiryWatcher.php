<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\service\GameAccountExpiryService;
use app\service\GameLogQueue;
use app\service\GatewayThirdPartyScriptRuntime;
use support\Log;
use Throwable;
use Workerman\Timer;

class GameAccountExpiryWatcher
{
    private const INTERVAL_SECONDS = 5;
    private const BATCH_LIMIT = 200;

    public function onWorkerStart($worker = null): void
    {
        Timer::add(self::INTERVAL_SECONDS, fn () => $this->tick());
    }

    public function tick(): void
    {
        try {
            $service = new GameAccountExpiryService(
                new DbGameAccountRepository(),
                new GatewayThirdPartyScriptRuntime(),
                new GameLogQueue()
            );
            $service->stopExpiredActiveAccounts(self::BATCH_LIMIT);
        } catch (Throwable $e) {
            Log::error('Game account expiry watcher failed', ['error' => $e->getMessage()]);
        }
    }
}
