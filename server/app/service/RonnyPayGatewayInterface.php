<?php

namespace app\service;

interface RonnyPayGatewayInterface
{
    public function createOrder(array $order): array;
    public function queryOrder(string $merchantOrder): array;
}
