<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\model\GameAssistUser;
use plugin\admin\app\service\GameAssistQuotaLogAdminService;
use plugin\admin\app\service\GameAssistUserAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

class GameAssistUserController extends Crud
{
    protected $model = null;

    private GameAssistUserAdminService $service;

    private GameAssistQuotaLogAdminService $quotaLogService;

    public function __construct()
    {
        $this->model = new GameAssistUser();
        $this->service = new GameAssistUserAdminService(I18n::localeFromRequest());
        $this->quotaLogService = new GameAssistQuotaLogAdminService(I18n::localeFromRequest());
    }

    public function index(): Response
    {
        return raw_view('game-assist-user/index');
    }

    public function insert(Request $request): Response
    {
        throw new BusinessException(I18n::t('admin.gameassist.create_forbidden', [], I18n::localeFromRequest()));
    }

    public function delete(Request $request): Response
    {
        throw new BusinessException(I18n::t('admin.gameassist.delete_forbidden', [], I18n::localeFromRequest()));
    }

    public function resetPassword(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return raw_view('game-assist-user/reset-password');
        }

        $id = (int)$request->post('id');
        $password = (string)$request->post('password', '');
        $user = $this->model->find($id);
        if (!$user) {
            throw new BusinessException(I18n::t('admin.gameassist.user_not_found', [], I18n::localeFromRequest()), 2);
        }

        try {
            $user->password_hash = $this->service->buildPasswordHash($password);
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        $user->save();
        return $this->json(0);
    }

    public function grantQuota(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return raw_view('game-assist-user/grant-quota');
        }

        $id = (int)$request->post('id');
        $points = $this->positiveInteger($request->post('points', ''));
        $remark = (string)$request->post('remark', '');

        try {
            $result = $this->service->grantQuota($id, $points, $remark, admin_id());
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return $this->json(0, 'ok', $result);
    }

    /**
     * 配额日志
     */
    public function quotaLogs(): Response
    {
        return raw_view('game-assist-user/quota-logs');
    }

    /**
     * 管理员添加记录
     */
    public function quotaGrantRecords(Request $request): Response
    {
        return $this->quotaLogResponse(fn (): array => $this->quotaLogService->grantRecords($request->get()));
    }

    /**
     * 用户配额记录
     */
    public function quotaConsumeRecords(Request $request): Response
    {
        return $this->quotaLogResponse(fn (): array => $this->quotaLogService->consumeRecords($request->get()));
    }

    /**
     * 用户游戏账号
     */
    public function gameAccounts(Request $request): Response
    {
        try {
            $result = $this->service->gameAccounts((int)$request->get('user_id'), $request->get());
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return json([
            'code' => 0,
            'msg' => 'ok',
            'count' => (int)$result['count'],
            'data' => $result['data'],
            'user' => $result['user'],
        ]);
    }

    protected function selectInput(Request $request): array
    {
        [$where, $format, $limit, $field, $order] = parent::selectInput($request);
        foreach (['account', 'email', 'nickname', 'bound_role_id'] as $column) {
            if (isset($where[$column]) && !is_array($where[$column])) {
                $where[$column] = ['like', $where[$column]];
            }
        }
        return [$where, $format, $limit, $field, $order];
    }

    protected function updateInput(Request $request): array
    {
        $primaryKey = $this->model->getKeyName();
        $id = $request->post($primaryKey);
        if (!$this->model->find($id)) {
            throw new BusinessException(I18n::t('admin.gameassist.user_not_found', [], I18n::localeFromRequest()), 2);
        }

        try {
            $data = $this->service->filterStatusUpdate($request->post());
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return [$id, $data];
    }

    protected function afterQuery($items)
    {
        return $this->service->sanitizeRows($items);
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            $points = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $points = (int)trim($value);
        } else {
            throw new BusinessException(I18n::t('admin.gameassist.quota_positive', [], I18n::localeFromRequest()), 2);
        }

        if ($points <= 0) {
            throw new BusinessException(I18n::t('admin.gameassist.quota_positive', [], I18n::localeFromRequest()), 2);
        }
        return $points;
    }

    private function quotaLogResponse(callable $callback): Response
    {
        try {
            $result = $callback();
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return json([
            'code' => 0,
            'msg' => 'ok',
            'count' => (int)$result['count'],
            'data' => $result['data'],
        ]);
    }
}
