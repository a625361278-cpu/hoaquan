<?php

namespace tests\Support;

use app\service\RonnyPayGatewayInterface;

final class FakeRonnyPayGateway implements RonnyPayGatewayInterface
{
    public int $createCalls = 0;
    public int $queryCalls = 0;
    public ?array $lastCreateOrder = null;

    public function __construct(
        public array|\Throwable $createResult,
        public array|\Throwable|null $queryResult = null
    ) {}

    public function createOrder(array $order): array
    {
        $this->createCalls++;
        $this->lastCreateOrder = $order;
        if ($this->createResult instanceof \Throwable) {
            throw $this->createResult;
        }
        return $this->createResult + [
            'merchant_order' => $order['merchant_order'],
            'total_fee' => $order['total_fee'],
        ];
    }

    public function queryOrder(string $merchantOrder): array
    {
        $this->queryCalls++;
        if ($this->queryResult instanceof \Throwable) {
            throw $this->queryResult;
        }
        return ($this->queryResult ?? []) + ['merchant_order' => $merchantOrder];
    }
}
