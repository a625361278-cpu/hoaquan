<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\service\GameConfigVisibilityAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

class GameAssistConfigVisibilityController extends Base
{
    private GameConfigVisibilityAdminService $service;

    public function __construct()
    {
        $this->service = new GameConfigVisibilityAdminService(locale: I18n::adminLocaleFromRequest());
    }

    public function index(): Response
    {
        return raw_view('game-assist-config-visibility/index');
    }

    public function get(): Response
    {
        try {
            return $this->json(0, 'ok', $this->service->config());
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }
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
