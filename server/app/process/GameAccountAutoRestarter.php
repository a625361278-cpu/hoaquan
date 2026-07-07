<?php

namespace app\process;

use app\repository\DbGameAccountRepository;
use app\service\GameAccountAutoRestartService;
use app\service\GameLogQueue;
use app\service\GatewayThirdPartyScriptRuntime;
use app\service\RedisThirdPartyScriptConnectionStore;
use app\service\SystemSettingService;
use app\service\ThirdPartyGateway;
use support\Log;
use Throwable;
use Workerman\Timer;

class GameAccountAutoRestarter
{
    private const INTERVAL_SECONDS = 2;
    private const BATCH_LIMIT = 100;
    private const RECONCILE_INTERVAL_SECONDS = 15;
    private const RECONCILE_BATCH_LIMIT = 500;

    private int $reconcileCursor = 0;
    private int $lastReconcileAt = 0;

    public function onWorkerStart($worker = null): void
    {
        Timer::add(self::INTERVAL_SECONDS, fn () => $this->tick());
    }

    public function tick(): void
    {
        try {
            $config = (new SystemSettingService())->thirdPartyConfig();
            if (empty($config['enabled'])) {
                return;
            }
            if (($config['transport'] ?? ThirdPartyGateway::TRANSPORT_WEBSOCKET) !== ThirdPartyGateway::TRANSPORT_WEBSOCKET) {
                return;
            }
            if (trim((string)($config['script_token'] ?? '')) === '' || trim((string)($config['credential_key'] ?? '')) === '') {
                return;
            }

            $store = new RedisThirdPartyScriptConnectionStore();
            $service = new GameAccountAutoRestartService(
                new DbGameAccountRepository(),
                new GatewayThirdPartyScriptRuntime($store),
                $store,
                new GameLogQueue(),
                (string)$config['credential_key']
            );
            $service->runDue(self::BATCH_LIMIT);
            if (time() - $this->lastReconcileAt >= self::RECONCILE_INTERVAL_SECONDS) {
                $result = $service->reconcileMissingBindings($this->reconcileCursor, self::RECONCILE_BATCH_LIMIT);
                $this->reconcileCursor = (int)($result['next_cursor'] ?? 0);
                $this->lastReconcileAt = time();
            }
        } catch (Throwable $e) {
            Log::error('Game account auto restarter failed', ['error' => $e->getMessage()]);
        }
    }
}
