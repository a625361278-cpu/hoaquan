<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\service\ThirdPartyConfigAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

class GameAssistThirdPartyConfigController extends Base
{
    private ThirdPartyConfigAdminService $service;

    public function __construct()
    {
        $this->service = new ThirdPartyConfigAdminService(locale: I18n::localeFromRequest());
    }

    public function index(): Response
    {
        return raw_view('game-assist-third-party-config/index');
    }

    public function get(): Response
    {
        return $this->json(0, 'ok', $this->service->config());
    }

    public function save(Request $request): Response
    {
        try {
            $this->service->save($request->post());
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return $this->json(0);
    }
}
