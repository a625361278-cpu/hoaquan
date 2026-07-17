<?php

namespace plugin\admin\app\service;

use app\service\PaymentOrderService;
use InvalidArgumentException;
use support\Db;

final class PaymentOrderAdminService
{
    public function list(array $filters): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $query = Db::table('ga_payment_orders as payment')
            ->leftJoin('ga_users as user', 'user.id', '=', 'payment.user_id');

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('payment.status', $status);
        }
        $provider = trim((string)($filters['provider'] ?? ''));
        if ($provider !== '') {
            if (!in_array($provider, ['ronnypay', 'mkpay'], true)) {
                throw new InvalidArgumentException('支付通道筛选值无效');
            }
            $query->where('payment.provider', $provider);
        }
        $user = trim((string)($filters['user'] ?? ''));
        if ($user !== '') {
            $query->where(function ($where) use ($user): void {
                if (ctype_digit($user)) {
                    $where->orWhere('payment.user_id', (int)$user);
                }
                $where->orWhere('user.account', 'like', '%' . $user . '%')
                    ->orWhere('user.nickname', 'like', '%' . $user . '%');
            });
        }
        $merchantOrder = trim((string)($filters['merchant_order'] ?? ''));
        if ($merchantOrder !== '') {
            $query->where('payment.merchant_order', 'like', '%' . $merchantOrder . '%');
        }
        $providerOrder = trim((string)($filters['provider_order_number'] ?? ''));
        if ($providerOrder !== '') {
            $query->where('payment.provider_order_number', 'like', '%' . $providerOrder . '%');
        }

        $count = (clone $query)->count('payment.id');
        $rows = $query->select([
                'payment.*',
                'user.account as user_account',
                'user.nickname as user_nickname',
            ])
            ->orderByDesc('payment.id')
            ->forPage($page, $limit)
            ->get()
            ->map(static function ($row): array {
                $data = (array)$row;
                $mobile = (string)($data['customer_mobile'] ?? '');
                $data['customer_mobile_masked'] = strlen($mobile) > 6
                    ? substr($mobile, 0, 3) . str_repeat('*', max(3, strlen($mobile) - 6)) . substr($mobile, -3)
                    : $mobile;
                $bankAccount = (string)($data['bank_account'] ?? '');
                $accountLength = mb_strlen($bankAccount);
                $data['bank_account_masked'] = $accountLength > 6
                    ? mb_substr($bankAccount, 0, 3) . '***' . mb_substr($bankAccount, -3)
                    : ($bankAccount === '' ? '' : '***');
                unset($data['customer_mobile'], $data['bank_account'], $data['idempotency_key'], $data['pay_url']);
                return $data;
            })
            ->all();

        return ['count' => $count, 'data' => $rows];
    }

    public function query(string $merchantOrder): array
    {
        $merchantOrder = trim($merchantOrder);
        if ($merchantOrder === '') {
            throw new InvalidArgumentException('商户订单号不能为空');
        }
        return (new PaymentOrderService())->query($merchantOrder);
    }
}
