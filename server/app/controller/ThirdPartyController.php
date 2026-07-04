<?php

namespace app\controller;

use app\repository\DbGameAccountRepository;
use app\exception\ApiException;
use app\service\GameAccountLogService;
use app\service\GameAccountService;
use app\service\SystemSettingService;
use app\service\ThirdPartyGateway;
use app\support\ApiResponse;
use app\support\I18n;
use support\Request;
use support\Response;

class ThirdPartyController extends BaseApiController
{
    public function config(Request $request, int $id): Response
    {
        $locale = I18n::localeFromRequest($request);
        $thirdPartyConfig = (new SystemSettingService())->thirdPartyConfig();
        $gateway = new ThirdPartyGateway($thirdPartyConfig, $locale);
        $gateway->verifyInboundRequest($request);

        $service = new GameAccountService(new DbGameAccountRepository(), $thirdPartyConfig, $locale);
        return ApiResponse::json($service->configForThirdParty($id));
    }

    public function applyConfig(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $gateway = new ThirdPartyGateway((new SystemSettingService())->thirdPartyConfig(), I18n::localeFromRequest($request));
        return ApiResponse::json($gateway->applyConfig($userId, $this->jsonInput($request)));
    }

    public function appendLogs(Request $request, int $id): Response
    {
        $locale = I18n::localeFromRequest($request);
        $gateway = new ThirdPartyGateway((new SystemSettingService())->thirdPartyConfig(), $locale);
        $gateway->verifyInboundRequest($request);

        $input = $this->jsonInput($request);
        $lines = $input['logs'] ?? $input['lines'] ?? [];
        if (!is_array($lines)) {
            throw new ApiException(I18n::t('api.game.logs_invalid', [], $locale), 422);
        }

        return ApiResponse::json((new GameAccountLogService(new DbGameAccountRepository(), $locale))->appendFromThirdParty($id, $lines));
    }
}
