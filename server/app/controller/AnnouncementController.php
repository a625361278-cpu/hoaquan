<?php

namespace app\controller;

use app\repository\DbAnnouncementRepository;
use app\service\AnnouncementService;
use app\support\ApiResponse;
use app\support\I18n;
use support\Request;
use support\Response;

class AnnouncementController extends BaseApiController
{
    public function latest(Request $request): Response
    {
        $this->authService($request)->resolveUserId($this->bearerToken($request));

        $service = new AnnouncementService(new DbAnnouncementRepository());
        return ApiResponse::json(ApiResponse::success([
            'announcement' => $service->latest(I18n::localeFromRequest($request)),
        ]));
    }
}
