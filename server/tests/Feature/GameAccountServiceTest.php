<?php

namespace tests\Feature;

use app\service\GameAccountService;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRepository;

class GameAccountServiceTest extends TestCase
{
    public function testEmptyGameAccountListIsReturnedAsRealEmptyState(): void
    {
        $service = new GameAccountService(new ArrayGameAccountRepository([]));

        $result = $service->listForUser(1);

        $this->assertSame(0, $result['code']);
        $this->assertSame([], $result['data']['items']);
        $this->assertSame('未添加游戏账号', $result['data']['empty_text']);
    }
}
