<?php

namespace app\controller;

use app\repository\DbGameAccountRepository;
use app\exception\ApiException;
use app\service\GameAccountLogService;
use app\service\GameAccountService;
use app\service\SystemSettingService;
use app\support\ApiResponse;
use app\support\I18n;
use support\Request;
use support\Response;

class GameAccountController extends BaseApiController
{
    public function index(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $service = $this->gameAccountService($request);
        return ApiResponse::json($service->listForUser($userId));
    }

    public function store(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->createFromLogin($userId, $this->jsonInput($request)));
    }

    public function config(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->configForUser($userId, $id));
    }

    public function saveConfig(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $input = $this->jsonInput($request);
        $config = $input['config'] ?? [];
        if (!is_array($config)) {
            throw new ApiException(I18n::t('api.game.config_invalid', [], I18n::localeFromRequest($request)), 422);
        }
        return ApiResponse::json($this->gameAccountService($request)->saveConfig($userId, $id, $config));
    }

    public function start(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->start($userId, $id));
    }

    public function stop(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->stop($userId, $id));
    }

    public function updatePassword(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->gameAccountService($request)->updatePassword(
            $userId,
            $id,
            (string)($input['game_password'] ?? '')
        ));
    }

    public function delete(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->delete($userId, $id));
    }

    public function quota(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->addQuota($userId, $id));
    }

    public function logs(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountLogService($request)->logsForUser(
            $userId,
            $id,
            (int)$request->get('lastLine', 0)
        ));
    }

    public function clearLogs(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountLogService($request)->clearForUser($userId, $id));
    }

    private function gameAccountService(Request $request): GameAccountService
    {
        return new GameAccountService(
            new DbGameAccountRepository(),
            (new SystemSettingService())->thirdPartyConfig(),
            I18n::localeFromRequest($request)
        );
    }

    private function gameAccountLogService(Request $request): GameAccountLogService
    {
        return new GameAccountLogService(new DbGameAccountRepository(), I18n::localeFromRequest($request));
    }
}
