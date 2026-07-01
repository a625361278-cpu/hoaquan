<?php

namespace tests\Support;

use app\service\TokenStoreInterface;

class MemoryTokenStore implements TokenStoreInterface
{
    private array $tokens = [];

    public function create(int $userId): string
    {
        $token = 'test-token-' . $userId;
        $this->tokens[$token] = $userId;
        return $token;
    }

    public function getUserId(string $token): ?int
    {
        return $this->tokens[$token] ?? null;
    }

    public function delete(string $token): void
    {
        unset($this->tokens[$token]);
    }
}
