<?php

namespace app\controller;

use app\repository\DbGameAccountRepository;
use app\service\GameAccountService;
use app\support\ApiResponse;
use support\Request;
use support\Response;

class GameAccountController extends BaseApiController
{
    public function index(Request $request): Response
    {
        $userId = $this->authService()->resolveUserId($this->bearerToken($request));
        $service = new GameAccountService(new DbGameAccountRepository());
        return ApiResponse::json($service->listForUser($userId));
    }

    public function store(Request $request): Response
    {
        $this->authService()->resolveUserId($this->bearerToken($request));
        $service = new GameAccountService(new DbGameAccountRepository());
        $service->createPlaceholder();
    }
}
