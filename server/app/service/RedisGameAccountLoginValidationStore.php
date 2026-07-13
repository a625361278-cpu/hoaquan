<?php

namespace app\service;

use support\Redis;

class RedisGameAccountLoginValidationStore implements GameAccountLoginValidationStoreInterface
{
    public const PREFIX = 'gameassist:game_account_login_validations:';
    private const RESULT_TTL = 600;
    private const ACTIVE_TTL = 25;

    public function __construct(private mixed $redis = null)
    {
    }

    public function begin(array $job): array
    {
        $validationId = (string)$job['validation_id'];
        $userId = (int)$job['user_id'];
        $fingerprint = (string)$job['fingerprint'];
        $payload = $this->encode($job);
        $script = <<<'LUA'
local existing = redis.call('GET', KEYS[3])
if existing then
    local raw = redis.call('GET', ARGV[6] .. existing)
    if raw then return {'existing', raw} end
    redis.call('DEL', KEYS[3])
end
local active = redis.call('GET', KEYS[2])
if active then
    local raw = redis.call('GET', ARGV[6] .. active)
    if raw then return {'conflict', raw} end
    redis.call('DEL', KEYS[2])
end
redis.call('SETEX', KEYS[1], tonumber(ARGV[4]), ARGV[2])
redis.call('SETEX', KEYS[2], tonumber(ARGV[5]), ARGV[1])
redis.call('SETEX', KEYS[3], tonumber(ARGV[4]), ARGV[1])
redis.call('ZADD', KEYS[4], tonumber(ARGV[3]), ARGV[1])
return {'created', ARGV[2]}
LUA;
        $result = $this->redis()->eval($script, [
            $this->jobKey($validationId),
            $this->activeUserKey($userId),
            $this->fingerprintKey($userId, $fingerprint),
            $this->deadlinesKey(),
            $validationId,
            $payload,
            (string)$job['expires_at'],
            (string)self::RESULT_TTL,
            (string)self::ACTIVE_TTL,
            $this->jobsPrefix(),
        ], 4);

        if (!is_array($result) || count($result) < 2) {
            throw new \RuntimeException('创建游戏账号登录验证任务失败');
        }
        return [
            'kind' => (string)$result[0],
            'job' => $this->decode((string)$result[1]),
        ];
    }

    public function activate(string $validationId, string $clientId): array
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return false end
local job = cjson.decode(raw)
if job['status'] ~= 'reserving' then return false end
job['status'] = 'verifying'
job['client_id'] = ARGV[1]
job['updated_at'] = tonumber(ARGV[2])
redis.call('SETEX', KEYS[1], tonumber(ARGV[3]), cjson.encode(job))
return cjson.encode(job)
LUA;
        $result = $this->redis()->eval($script, [
            $this->jobKey($validationId),
            $clientId,
            (string)time(),
            (string)self::RESULT_TTL,
        ], 1);
        if (!is_string($result) || $result === '') {
            throw new \RuntimeException('登录验证任务状态异常，不能绑定脚本连接');
        }
        return $this->decode($result);
    }

    public function abortStart(string $validationId): void
    {
        $job = $this->job($validationId);
        if (!$job) {
            return;
        }
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job['status'] ~= 'reserving' and job['status'] ~= 'verifying' then return 0 end
redis.call('DEL', KEYS[1])
redis.call('ZREM', KEYS[2], ARGV[1])
if redis.call('GET', KEYS[3]) == ARGV[1] then redis.call('DEL', KEYS[3]) end
if redis.call('GET', KEYS[4]) == ARGV[1] then redis.call('DEL', KEYS[4]) end
return 1
LUA;
        $this->redis()->eval($script, [
            $this->jobKey($validationId),
            $this->deadlinesKey(),
            $this->activeUserKey((int)$job['user_id']),
            $this->fingerprintKey((int)$job['user_id'], (string)$job['fingerprint']),
            $validationId,
        ], 4);
    }

    public function getForUser(int $userId, string $validationId): ?array
    {
        $job = $this->job($validationId);
        return $job && (int)($job['user_id'] ?? 0) === $userId ? $job : null;
    }

    public function forget(string $validationId): void
    {
        $job = $this->job($validationId);
        if (!$job) {
            $this->redis()->zRem($this->deadlinesKey(), $validationId);
            return;
        }
        $this->redis()->del($this->jobKey($validationId));
        $this->redis()->zRem($this->deadlinesKey(), $validationId);
        $this->deleteIfMatches($this->activeUserKey((int)$job['user_id']), $validationId);
        $this->deleteIfMatches($this->fingerprintKey((int)$job['user_id'], (string)$job['fingerprint']), $validationId);
    }

    public function claimResponse(string $validationId, string $requestId, string $sessionId): ?array
    {
        return $this->claim($validationId, $requestId, $sessionId, false);
    }

    public function claimTimeout(string $validationId): ?array
    {
        return $this->claim($validationId, '', '', true);
    }

    public function complete(string $validationId, string $status, string $message, int $accountId = 0, string $serverName = ''): array
    {
        if (!in_array($status, ['success', 'rejected', 'timeout', 'error'], true)) {
            throw new \InvalidArgumentException('Invalid login validation terminal status');
        }
        $job = $this->job($validationId);
        if (!$job || (string)($job['status'] ?? '') !== 'processing') {
            throw new \RuntimeException('登录验证任务未被领取，不能完成');
        }
        $job['status'] = $status;
        $job['message'] = $message;
        $job['account_id'] = $accountId;
        $job['server_name'] = $serverName;
        $job['updated_at'] = time();
        unset($job['credential_cipher']);
        $this->redis()->setEx($this->jobKey($validationId), self::RESULT_TTL, $this->encode($job));
        $this->redis()->zRem($this->deadlinesKey(), $validationId);
        $this->deleteIfMatches($this->activeUserKey((int)$job['user_id']), $validationId);
        if (in_array($status, ['timeout', 'error'], true)) {
            $this->deleteIfMatches($this->fingerprintKey((int)$job['user_id'], (string)$job['fingerprint']), $validationId);
        }
        return $job;
    }

    public function failPending(string $validationId, string $message): ?array
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return false end
local job = cjson.decode(raw)
if job['status'] ~= 'reserving' and job['status'] ~= 'verifying' then return false end
job['status'] = 'processing'
job['updated_at'] = tonumber(ARGV[1])
redis.call('SETEX', KEYS[1], tonumber(ARGV[2]), cjson.encode(job))
redis.call('ZREM', KEYS[2], ARGV[3])
return cjson.encode(job)
LUA;
        $result = $this->redis()->eval($script, [
            $this->jobKey($validationId),
            $this->deadlinesKey(),
            (string)time(),
            (string)self::RESULT_TTL,
            $validationId,
        ], 2);
        if (!is_string($result) || $result === '') {
            return null;
        }
        return $this->complete($validationId, 'error', $message);
    }

    public function dueValidationIds(int $now, int $limit): array
    {
        $rows = $this->redis()->zRangeByScore($this->deadlinesKey(), '-inf', (string)$now, ['limit' => [0, max(1, $limit)]]);
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    private function claim(string $validationId, string $requestId, string $sessionId, bool $timeout): ?array
    {
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then redis.call('ZREM', KEYS[2], ARGV[6]) return false end
local job = cjson.decode(raw)
if ARGV[1] == '1' then
    if job['status'] ~= 'reserving' and job['status'] ~= 'verifying' then return false end
else
    if job['status'] ~= 'verifying' then return false end
    if tostring(job['request_id'] or '') ~= ARGV[2] or tostring(job['session_id'] or '') ~= ARGV[3] then return false end
end
job['status'] = 'processing'
job['updated_at'] = tonumber(ARGV[4])
redis.call('SETEX', KEYS[1], tonumber(ARGV[5]), cjson.encode(job))
redis.call('ZREM', KEYS[2], ARGV[6])
return cjson.encode(job)
LUA;
        $result = $this->redis()->eval($script, [
            $this->jobKey($validationId),
            $this->deadlinesKey(),
            $timeout ? '1' : '0',
            $requestId,
            $sessionId,
            (string)time(),
            (string)self::RESULT_TTL,
            $validationId,
        ], 2);
        return is_string($result) && $result !== '' ? $this->decode($result) : null;
    }

    private function job(string $validationId): ?array
    {
        $raw = $this->redis()->get($this->jobKey($validationId));
        return is_string($raw) && $raw !== '' ? $this->decode($raw) : null;
    }

    private function deleteIfMatches(string $key, string $expected): void
    {
        $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";
        $this->redis()->eval($script, [$key, $expected], 1);
    }

    private function jobKey(string $id): string
    {
        return $this->jobsPrefix() . $id;
    }

    private function jobsPrefix(): string
    {
        return self::PREFIX . 'jobs:';
    }

    private function activeUserKey(int $userId): string
    {
        return self::PREFIX . 'active_users:' . $userId;
    }

    private function fingerprintKey(int $userId, string $fingerprint): string
    {
        return self::PREFIX . 'fingerprints:' . $userId . ':' . $fingerprint;
    }

    private function deadlinesKey(): string
    {
        return self::PREFIX . 'deadlines';
    }

    private function redis(): mixed
    {
        return $this->redis ?? Redis::connection();
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('游戏账号登录验证任务编码失败');
        }
        return $json;
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true);
        if (!is_array($value)) {
            throw new \RuntimeException('游戏账号登录验证任务数据损坏');
        }
        return $value;
    }
}
