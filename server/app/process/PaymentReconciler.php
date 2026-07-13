<?php

namespace app\process;

use app\service\PaymentOrderService;
use app\service\RonnyPayConfig;
use support\Log;
use Workerman\Timer;

final class PaymentReconciler
{
    public function onWorkerStart(): void
    {
        $config = new RonnyPayConfig();
        if (!$config->apiConfigured()) {
            Log::warning('payment_reconciler 未启动查单：RonnyPay API 配置不完整');
        }
        Timer::add(60, function () use ($config): void {
            if (!$config->apiConfigured()) {
                return;
            }
            try {
                (new PaymentOrderService($config))->reconcileDue(50);
            } catch (\Throwable $e) {
                Log::error('payment_reconciler 执行失败', ['message' => $e->getMessage()]);
            }
        });
    }
}
