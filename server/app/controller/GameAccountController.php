<?php

namespace app\controller;

use app\repository\DbGameAccountRepository;
use app\exception\ApiException;
use app\service\GameAccountLogService;
use app\service\GameAccountService;
use app\service\GameConfigVisibilityService;
use app\service\RedisGameAccountTakeoverNoticeStore;
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

    public function loginValidation(Request $request, string $validationId): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->loginValidationStatus($userId, $validationId));
    }

    public function config(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $payload = $this->gameAccountService($request)->configForUser($userId, $id);
        $payload['data']['ui_hidden_paths'] = (new GameConfigVisibilityService(
            locale: I18n::localeFromRequest($request)
        ))->hiddenPaths();
        return ApiResponse::json($payload);
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

    public function importConfig(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->gameAccountService($request)->importConfig(
            $userId,
            $id,
            (int)($input['source_account_id'] ?? 0)
        ));
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
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->gameAccountService($request)->addQuota(
            $userId,
            $id,
            $this->nonNegativeInteger($input['extra_points'] ?? 0, I18n::localeFromRequest($request))
        ));
    }

    public function logs(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountLogService($request)->logsForUser(
            $userId,
            $id,
            (int)$request->get('lastLine', 0),
            (int)$request->get('lastEvent', 0)
        ));
    }

    public function updateCredential(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountService($request)->updateCredential(
            $userId,
            $id,
            $this->jsonInput($request)
        ));
    }

    public function clearLogs(Request $request, int $id): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->gameAccountLogService($request)->clearForUser(
            $userId,
            $id,
            (string)$request->get('type', 'normal')
        ));
    }

    private function gameAccountService(Request $request): GameAccountService
    {
        $settings = new SystemSettingService();
        $config = $settings->thirdPartyConfig();
        $config['max_accounts_per_user'] = $settings->gameAccountMaxCount();

        return new GameAccountService(
            new DbGameAccountRepository(),
            $config,
            I18n::localeFromRequest($request),
            takeoverNotices: new RedisGameAccountTakeoverNoticeStore()
        );
    }

    private function gameAccountLogService(Request $request): GameAccountLogService
    {
        return new GameAccountLogService(new DbGameAccountRepository(), I18n::localeFromRequest($request));
    }

    private function nonNegativeInteger(mixed $value, string $locale): int
    {
        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $intValue = (int)trim($value);
        } else {
            throw new ApiException(I18n::t('api.game.quota_extra_invalid', [], $locale), 422);
        }

        if ($intValue < 0) {
            throw new ApiException(I18n::t('api.game.quota_extra_invalid', [], $locale), 422);
        }
        return $intValue;
    }
}
