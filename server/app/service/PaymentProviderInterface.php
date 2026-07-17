<?php

namespace app\service;

interface PaymentProviderInterface
{
    public function code(): string;
    public function label(): string;
    public function assertCanCreateOrder(): void;
    public function apiConfigured(): bool;
    public function orderMetadata(): array;
    public function createOrder(array $order): array;
    public function queryOrder(array $order): array;
    public function parseCallback(array $parameters): array;
}
