<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

Route::options('/api/{path:.+}', function () {
    return response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    ]);
});

Route::group('/api', function () {
    Route::post('/auth/login', [app\controller\AuthController::class, 'login']);
    Route::post('/auth/email-code/send', [app\controller\AuthController::class, 'sendEmailCode']);
    Route::post('/auth/password/email-code/send', [app\controller\AuthController::class, 'sendPasswordEmailCode']);
    Route::post('/auth/password/reset', [app\controller\AuthController::class, 'resetPassword']);
    Route::post('/auth/register', [app\controller\AuthController::class, 'register']);
    Route::post('/auth/logout', [app\controller\AuthController::class, 'logout']);
    Route::get('/me', [app\controller\AuthController::class, 'me']);
    Route::get('/game-accounts', [app\controller\GameAccountController::class, 'index']);
    Route::post('/game-accounts', [app\controller\GameAccountController::class, 'store']);
    Route::post('/third-party/apply-config', [app\controller\ThirdPartyController::class, 'applyConfig']);
});
