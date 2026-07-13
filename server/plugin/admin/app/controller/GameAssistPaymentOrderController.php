<?php

namespace plugin\admin\app\controller;

use InvalidArgumentException;
use plugin\admin\app\service\PaymentOrderAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

final class GameAssistPaymentOrderController extends Base
{
    private PaymentOrderAdminService $service;

    public function __construct()
    {
        $this->service = new PaymentOrderAdminService();
    }

    public function index(): Response
    {
        return raw_view('game-assist-payment-order/index');
    }

    public function select(Request $request): Response
    {
        $result = $this->service->list($request->get());
        return json(['code' => 0, 'msg' => 'ok', 'count' => $result['count'], 'data' => $result['data']]);
    }

    public function query(Request $request): Response
    {
        try {
            $order = $this->service->query((string)$request->post('merchant_order', ''));
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw new BusinessException($e->getMessage(), 2);
        }
        return $this->json(0, 'ok', ['order' => $order]);
    }
}
