<?php

namespace app\controller;

use app\exception\ApiException;
use app\exception\PaymentProviderException;
use app\service\PaymentCallbackIpWhitelist;
use app\service\PaymentOrderService;
use app\support\ApiResponse;
use InvalidArgumentException;
use RuntimeException;
use support\Log;
use support\Request;
use support\Response;

final class RechargeController extends BaseApiController
{
    public function config(): Response
    {
        return ApiResponse::json(ApiResponse::success((new PaymentOrderService())->config()));
    }

    public function store(Request $request): Response
    {
        $userId = $this->authService($request)->resolveUserId($this->bearerToken($request));
        try {
            $order = (new PaymentOrderService())->create($userId, $this->jsonInput($request));
        } catch (InvalidArgumentException|PaymentProviderException $e) {
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
        } catch (InvalidArgumentException|PaymentProviderException $e) {
            throw new ApiException($e->getMessage(), 404);
        }
        return ApiResponse::json(ApiResponse::success(['order' => $order]));
    }

    public function notifyRonnyPay(Request $request): Response
    {
        try {
            (new PaymentCallbackIpWhitelist())->assertAllowed((string)$request->getRealIp());
            (new PaymentOrderService())->handleCallback('ronnypay', $request->post());
            return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (InvalidArgumentException|PaymentProviderException $e) {
            Log::warning('RonnyPay callback rejected', ['reason' => $e->getMessage()]);
            return response('fail', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (\Throwable $e) {
            Log::error('RonnyPay callback processing failed', ['reason' => $e->getMessage()]);
            return response('fail', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    public function notifyMkPay(Request $request): Response
    {
        try {
            (new PaymentCallbackIpWhitelist())->assertAllowed((string)$request->getRealIp());
            (new PaymentOrderService())->handleCallback('mkpay', $this->jsonInput($request));
            return response('SUCCESS', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (InvalidArgumentException|PaymentProviderException $e) {
            Log::warning('MkPay callback rejected', ['reason' => $e->getMessage()]);
            return response('FAIL', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (\Throwable $e) {
            Log::error('MkPay callback processing failed', ['reason' => $e->getMessage()]);
            return response('FAIL', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }
}
