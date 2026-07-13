<?php

namespace app\controller;

use app\repository\DbUserRepository;
use app\service\AuthService;
use app\service\RedisEmailCodeStore;
use app\service\RedisTokenStore;
use app\service\SmtpMailer;
use app\service\SystemSettingService;
use app\support\I18n;
use support\Request;

class BaseApiController
{
    protected function authService(?Request $request = null): AuthService
    {
        $settings = new SystemSettingService();
        return new AuthService(
            new DbUserRepository(),
            new RedisTokenStore(),
            new RedisEmailCodeStore(I18n::localeFromRequest($request)),
            new SmtpMailer($settings, I18n::localeFromRequest($request)),
            I18n::localeFromRequest($request),
            $settings->authVerificationMode(),
            $settings->registrationRewardPoints()
        );
    }

    protected function bearerToken(Request $request): string
    {
        $authorization = $request->header('authorization', '');
        if (stripos($authorization, 'Bearer ') === 0) {
            return trim(substr($authorization, 7));
        }
        return '';
    }

    protected function jsonInput(Request $request): array
    {
        $raw = $request->rawBody();
        if ($raw === '') {
            return $request->post();
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
