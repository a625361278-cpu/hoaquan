<?php

namespace app\process;

use app\service\PaymentOrderService;
use support\Log;
use Workerman\Timer;

final class PaymentReconciler
{
    public function onWorkerStart(): void
    {
        Timer::add(60, function (): void {
            try {
                (new PaymentOrderService())->reconcileDue(50);
            } catch (\Throwable $e) {
                Log::error('payment_reconciler 执行失败', ['message' => $e->getMessage()]);
            }
        });
    }
}
