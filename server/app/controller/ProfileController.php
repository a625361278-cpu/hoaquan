<?php

namespace app\controller;

use app\repository\DbUserRepository;
use app\service\ProfileService;
use app\service\SystemSettingService;
use app\support\ApiResponse;
use app\support\I18n;
use support\Request;
use support\Response;

class ProfileController extends BaseApiController
{
    public function show(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        return ApiResponse::json($this->profileService($request)->summary($userId, $this->origin($request)));
    }

    private function profileService(Request $request): ProfileService
    {
        return new ProfileService(new DbUserRepository(), new SystemSettingService(), I18n::localeFromRequest($request));
    }

    private function origin(Request $request): string
    {
        $origin = (string)$request->header('origin', '');
        if ($origin !== '') {
            return $origin;
        }

        $scheme = (string)$request->header('x-forwarded-proto', 'http');
        $host = (string)($request->header('host', '') ?: $request->host());
        return $scheme . '://' . $host;
    }
}
