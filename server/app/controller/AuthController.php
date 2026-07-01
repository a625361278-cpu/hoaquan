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
        return ApiResponse::json($this->authService()->login(
            (string)($input['account'] ?? ''),
            (string)($input['password'] ?? '')
        ));
    }

    public function register(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService()->register(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? ''),
            (string)($input['email_code'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['password_confirmation'] ?? '')
        ));
    }

    public function sendEmailCode(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService()->sendRegisterEmailCode(
            (string)($input['email'] ?? '')
        ));
    }

    public function sendPasswordEmailCode(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService()->sendPasswordResetEmailCode(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? '')
        ));
    }

    public function resetPassword(Request $request): Response
    {
        $input = $this->jsonInput($request);
        return ApiResponse::json($this->authService()->resetPassword(
            (string)($input['account'] ?? ''),
            (string)($input['email'] ?? ''),
            (string)($input['email_code'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['password_confirmation'] ?? '')
        ));
    }

    public function logout(Request $request): Response
    {
        return ApiResponse::json($this->authService()->logout($this->bearerToken($request)));
    }

    public function me(Request $request): Response
    {
        return ApiResponse::json($this->authService()->currentUser($this->bearerToken($request)));
    }
}
