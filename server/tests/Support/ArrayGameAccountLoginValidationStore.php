<?php

namespace tests\Support;

use app\service\GameAccountLoginValidationStoreInterface;

class ArrayGameAccountLoginValidationStore implements GameAccountLoginValidationStoreInterface
{
    public array $jobs = [];

    public function begin(array $job): array
    {
        foreach ($this->jobs as $existing) {
            if ((int)$existing['user_id'] === (int)$job['user_id'] && (string)$existing['fingerprint'] === (string)$job['fingerprint']) {
                return ['kind' => 'existing', 'job' => $existing];
            }
            if ((int)$existing['user_id'] === (int)$job['user_id'] && in_array((string)$existing['status'], ['reserving', 'verifying', 'processing'], true)) {
                return ['kind' => 'conflict', 'job' => $existing];
            }
        }
        $this->jobs[$job['validation_id']] = $job;
        return ['kind' => 'created', 'job' => $job];
    }

    public function activate(string $validationId, string $clientId): array
    {
        $this->jobs[$validationId]['status'] = 'verifying';
        $this->jobs[$validationId]['client_id'] = $clientId;
        return $this->jobs[$validationId];
    }

    public function abortStart(string $validationId): void
    {
        if (isset($this->jobs[$validationId]) && in_array($this->jobs[$validationId]['status'], ['reserving', 'verifying'], true)) {
            unset($this->jobs[$validationId]);
        }
    }

    public function getForUser(int $userId, string $validationId): ?array
    {
        $job = $this->jobs[$validationId] ?? null;
        return $job && (int)$job['user_id'] === $userId ? $job : null;
    }

    public function forget(string $validationId): void
    {
        unset($this->jobs[$validationId]);
    }

    public function claimResponse(string $validationId, string $requestId, string $sessionId): ?array
    {
        $job = $this->jobs[$validationId] ?? null;
        if (!$job || $job['status'] !== 'verifying' || $job['request_id'] !== $requestId || $job['session_id'] !== $sessionId) {
            return null;
        }
        $this->jobs[$validationId]['status'] = 'processing';
        return $this->jobs[$validationId];
    }

    public function claimTimeout(string $validationId): ?array
    {
        $job = $this->jobs[$validationId] ?? null;
        if (!$job || !in_array($job['status'], ['reserving', 'verifying'], true)) {
            return null;
        }
        $this->jobs[$validationId]['status'] = 'processing';
        return $this->jobs[$validationId];
    }

    public function complete(string $validationId, string $status, string $message, int $accountId = 0, string $serverName = ''): array
    {
        $job = $this->jobs[$validationId];
        $job['status'] = $status;
        $job['message'] = $message;
        $job['account_id'] = $accountId;
        $job['server_name'] = $serverName;
        unset($job['credential_cipher']);
        return $this->jobs[$validationId] = $job;
    }

    public function failPending(string $validationId, string $message): ?array
    {
        if (!isset($this->jobs[$validationId]) || !in_array($this->jobs[$validationId]['status'], ['reserving', 'verifying'], true)) {
            return null;
        }
        $this->jobs[$validationId]['status'] = 'processing';
        return $this->complete($validationId, 'error', $message);
    }

    public function dueValidationIds(int $now, int $limit): array
    {
        $ids = [];
        foreach ($this->jobs as $id => $job) {
            if (in_array($job['status'], ['reserving', 'verifying'], true) && (int)$job['expires_at'] <= $now) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, $limit);
    }
}
