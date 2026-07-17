<?php

namespace app\service;

interface PaymentGatewayInterface
{
    public function createOrder(array $order): array;
    public function queryOrder(string $merchantOrder): array;
}
