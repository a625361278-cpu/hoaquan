<?php

namespace app\process;

use app\service\GameAccountLoginValidationStoreInterface;
use app\service\RedisGameAccountLoginValidationStore;
use app\service\RedisThirdPartyScriptConnectionStore;
use app\service\ThirdPartyScriptConnectionStoreInterface;
use app\support\I18n;
use GatewayWorker\Lib\Gateway;
use support\Log;
use Throwable;
use Workerman\Timer;

class GameAccountLoginValidationWatcher
{
    private const INTERVAL_SECONDS = 1;
    private const BATCH_SIZE = 100;

    public function __construct(
        private ?GameAccountLoginValidationStoreInterface $validations = null,
        private ?ThirdPartyScriptConnectionStoreInterface $connections = null,
        private mixed $closer = null
    ) {
        $this->validations ??= new RedisGameAccountLoginValidationStore();
        $this->connections ??= new RedisThirdPartyScriptConnectionStore();
        $this->closer ??= static fn (string $clientId) => Gateway::closeClient($clientId);
    }

    public function onWorkerStart(): void
    {
        Gateway::$registerAddress = (string)app_env('GATEWAY_REGISTER_ADDRESS', '127.0.0.1:1238');
        Timer::add(self::INTERVAL_SECONDS, fn () => $this->tick());
    }

    public function tick(?int $now = null): void
    {
        $now ??= time();
        foreach ($this->validations->dueValidationIds($now, self::BATCH_SIZE) as $validationId) {
            try {
                $job = $this->validations->claimTimeout($validationId);
                if (!$job) {
                    continue;
                }
                $this->validations->complete(
                    $validationId,
                    'timeout',
                    I18n::t('api.game.login_validation_timeout', [], (string)($job['locale'] ?? I18n::DEFAULT_LOCALE))
                );
                $this->closeMatchingConnection($job);
                Log::warning('Game account login validation timed out', [
                    'validation_id' => $validationId,
                    'user_id' => (int)($job['user_id'] ?? 0),
                    'client_id' => (string)($job['client_id'] ?? ''),
                    'login_method' => (int)($job['login_method'] ?? 0),
                ]);
            } catch (Throwable $e) {
                Log::error('Game account login validation timeout handling failed', [
                    'validation_id' => $validationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function closeMatchingConnection(array $job): void
    {
        $clientId = (string)($job['client_id'] ?? '');
        if ($clientId === '') {
            return;
        }
        $state = $this->connections->connection($clientId);
        if (!$state
            || (string)($state['state'] ?? '') !== 'validating'
            || (string)($state['validation_id'] ?? '') !== (string)$job['validation_id']
            || (string)($state['request_id'] ?? '') !== (string)$job['request_id']
            || (string)($state['session_id'] ?? '') !== (string)$job['session_id']) {
            return;
        }
        ($this->closer)($clientId);
    }
}
