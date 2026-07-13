<?php

namespace app\controller;

use app\exception\ApiException;
use app\exception\RonnyPayException;
use app\service\PaymentOrderService;
use app\support\ApiResponse;
use InvalidArgumentException;
use RuntimeException;
use support\Log;
use support\Request;
use support\Response;

final class RechargeController extends BaseApiController
{
    public function store(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        try {
            $order = (new PaymentOrderService())->create($userId, $this->jsonInput($request));
        } catch (InvalidArgumentException|RonnyPayException $e) {
            throw new ApiException($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            throw new ApiException($e->getMessage(), 503);
        }
        return ApiResponse::json(ApiResponse::success(['order' => $order]));
    }

    public function show(Request $request, string $merchant_order): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        try {
            $order = (new PaymentOrderService())->getForUser($userId, $merchant_order);
        } catch (InvalidArgumentException|RonnyPayException $e) {
            throw new ApiException($e->getMessage(), 404);
        }
        return ApiResponse::json(ApiResponse::success(['order' => $order]));
    }

    public function notify(Request $request): Response
    {
        try {
            (new PaymentOrderService())->handleCallback($request->post());
            return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (InvalidArgumentException|RonnyPayException $e) {
            Log::warning('RonnyPay callback rejected', ['reason' => $e->getMessage()]);
            return response('fail', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (\Throwable $e) {
            Log::error('RonnyPay callback processing failed', ['reason' => $e->getMessage()]);
            return response('fail', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }
}
