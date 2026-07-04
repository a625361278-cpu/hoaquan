<?php

namespace plugin\admin\app\controller;

use app\repository\AnnouncementRepositoryInterface;
use app\service\AnnouncementService;
use app\support\I18n;
use plugin\admin\app\model\GameAssistAnnouncement;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

class GameAssistAnnouncementController extends Crud
{
    protected $model = null;

    public function __construct()
    {
        $this->model = new GameAssistAnnouncement();
    }

    public function index(): Response
    {
        return raw_view('game-assist-announcement/index');
    }

    public function insert(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return raw_view('game-assist-announcement/insert');
        }

        return parent::insert($request);
    }

    public function update(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return raw_view('game-assist-announcement/update');
        }

        return parent::update($request);
    }

    protected function selectInput(Request $request): array
    {
        [$where, $format, $limit, $field, $order] = parent::selectInput($request);
        foreach (['title_zh_cn', 'title_vi'] as $column) {
            if (isset($where[$column]) && !is_array($where[$column])) {
                $where[$column] = ['like', $where[$column]];
            }
        }
        return [$where, $format, $limit, $field, $order];
    }

    protected function insertInput(Request $request): array
    {
        return $this->validatedInput(parent::insertInput($request));
    }

    protected function updateInput(Request $request): array
    {
        [$id, $data] = parent::updateInput($request);
        return [$id, $this->validatedInput($data)];
    }

    private function validatedInput(array $data): array
    {
        foreach (['title_zh_cn', 'title_vi', 'content_zh_cn', 'content_vi', 'published_at'] as $field) {
            if (trim((string)($data[$field] ?? '')) === '') {
                throw new BusinessException(I18n::t('admin.announcement.field_required', [], I18n::localeFromRequest()), 2);
            }
        }

        if (!isset($data['status']) || !in_array((string)$data['status'], ['0', '1'], true)) {
            throw new BusinessException(I18n::t('admin.announcement.status_invalid', [], I18n::localeFromRequest()), 2);
        }
        $data['status'] = (int)$data['status'];

        try {
            $validator = new AnnouncementService(new class implements AnnouncementRepositoryInterface {
                public function latestEnabled(): ?array
                {
                    return null;
                }
            });
            $validator->parseContentBlocks((string)$data['content_zh_cn']);
            $validator->parseContentBlocks((string)$data['content_vi']);
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }

        return $data;
    }
}
