<?php

namespace app\controller;

use app\support\ApiResponse;
use support\Request;
use support\Response;

class AuthController extends BaseApiController
{
    public function login(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService($request)->login(
            (string)($input['account'] ?? ''),
            (string)($input['password'] ?? '')
        ));
    }

    public function register(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService($request)->register(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? ''),
            (string)($input['email_code'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['password_confirmation'] ?? ''),
            (string)($input['invite_code'] ?? ''),
            (string)$request->getRealIp()
        ));
    }

    public function sendEmailCode(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService($request)->sendRegisterEmailCode(
            (string)($input['email'] ?? '')
        ));
    }

    public function sendPasswordEmailCode(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService($request)->sendPasswordResetEmailCode(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? '')
        ));
    }

    public function resetPassword(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService($request)->resetPassword(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? ''),
            (string)($input['email_code'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['password_confirmation'] ?? '')
        ));
    }

    public function logout(Request $request): Response
    {
        return ApiResponse::json($this->authService($request)->logout($this->bearerToken($request)));
    }

    public function me(Request $request): Response
    {
        return ApiResponse::json($this->authService($request)->currentUser($this->bearerToken($request)));
    }
}
