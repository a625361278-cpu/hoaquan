<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\service\PaymentConfigAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

final class GameAssistPaymentConfigController extends Base
{
    private PaymentConfigAdminService $service;

    public function __construct()
    {
        $this->service = new PaymentConfigAdminService(locale: I18n::localeFromRequest());
    }

    public function index(): Response
    {
        return raw_view('game-assist-payment-config/index');
    }

    public function get(): Response
    {
        return $this->json(0, 'ok', $this->service->config());
    }

    public function save(Request $request): Response
    {
        try {
            $this->service->save($request->post());
        } catch (RuntimeException $e) {
            throw new BusinessException($e->getMessage(), 2);
        }
        return $this->json(0);
    }
}
