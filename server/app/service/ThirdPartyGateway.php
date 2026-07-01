<?php

namespace app\service;

use app\exception\ApiException;

class ThirdPartyGateway
{
    public function __construct(private array $config)
    {
    }

    public function applyConfig(int $userId, array $payload): never
    {
        if (empty($this->config['enabled'])) {
            throw new ApiException('第三方接口未启用，不能同步配置', 409);
        }

        throw new ApiException('第三方配置同步尚未实现真实接口', 501);
    }
}
