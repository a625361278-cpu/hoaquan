<?php

namespace app\controller;

use app\service\SystemSettingService;
use app\service\ThirdPartyGateway;
use app\support\ApiResponse;
use support\Request;
use support\Response;

class ThirdPartyController extends BaseApiController
{
    public function applyConfig(Request $request): Response
    {
        $userId = $this->authService()->resolveUserId($this->bearerToken($request));
        $gateway = new ThirdPartyGateway((new SystemSettingService())->thirdPartyConfig());
        $gateway->applyConfig($userId, $this->jsonInput($request));
    }
}
