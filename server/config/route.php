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
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Locale, X-Timestamp, X-Signature',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    ]);
});

Route::get('/ws/game-accounts/{id}/logs', function () {
    return response('Game account log WebSocket is configured at the reverse-proxy/runtime layer.', 426);
});

Route::group('/api', function () {
    Route::get('/i18n/messages', [app\controller\I18nController::class, 'messages']);
    Route::get('/auth/config', [app\controller\AuthController::class, 'config']);
    Route::post('/auth/login', [app\controller\AuthController::class, 'login']);
    Route::post('/auth/email-code/send', [app\controller\AuthController::class, 'sendEmailCode']);
    Route::post('/auth/password/email-code/send', [app\controller\AuthController::class, 'sendPasswordEmailCode']);
    Route::post('/auth/password/security-question', [app\controller\AuthController::class, 'passwordSecurityQuestion']);
    Route::post('/auth/password/reset', [app\controller\AuthController::class, 'resetPassword']);
    Route::post('/auth/password/change', [app\controller\AuthController::class, 'changePassword']);
    Route::post('/auth/register', [app\controller\AuthController::class, 'register']);
    Route::post('/auth/logout', [app\controller\AuthController::class, 'logout']);
    Route::get('/me', [app\controller\AuthController::class, 'me']);
    Route::get('/profile', [app\controller\ProfileController::class, 'show']);
    Route::get('/announcements/latest', [app\controller\AnnouncementController::class, 'latest']);
    Route::get('/recharge/config', [app\controller\RechargeController::class, 'config']);
    Route::post('/recharge/orders', [app\controller\RechargeController::class, 'store']);
    Route::get('/recharge/orders/{merchant_order}', [app\controller\RechargeController::class, 'show']);
    Route::post('/recharge/ronnypay/notify', [app\controller\RechargeController::class, 'notifyRonnyPay']);
    Route::post('/recharge/mkpay/notify', [app\controller\RechargeController::class, 'notifyMkPay']);
    Route::get('/game-accounts', [app\controller\GameAccountController::class, 'index']);
    Route::post('/game-accounts', [app\controller\GameAccountController::class, 'store']);
    Route::get('/game-account-validations/{validationId}', [app\controller\GameAccountController::class, 'loginValidation']);
    Route::post('/game-accounts/{id}/start', [app\controller\GameAccountController::class, 'start']);
    Route::post('/game-accounts/{id}/stop', [app\controller\GameAccountController::class, 'stop']);
    Route::post('/game-accounts/{id}/password', [app\controller\GameAccountController::class, 'updatePassword']);
    Route::post('/game-accounts/{id}/credential', [app\controller\GameAccountController::class, 'updateCredential']);
    Route::delete('/game-accounts/{id}', [app\controller\GameAccountController::class, 'delete']);
    Route::post('/game-accounts/{id}/quota', [app\controller\GameAccountController::class, 'quota']);
    Route::get('/game-accounts/{id}/logs', [app\controller\GameAccountController::class, 'logs']);
    Route::delete('/game-accounts/{id}/logs', [app\controller\GameAccountController::class, 'clearLogs']);
    Route::get('/game-accounts/{id}/config', [app\controller\GameAccountController::class, 'config']);
    Route::post('/game-accounts/{id}/config', [app\controller\GameAccountController::class, 'saveConfig']);
    Route::post('/game-accounts/{id}/config/import', [app\controller\GameAccountController::class, 'importConfig']);
    Route::get('/third-party/game-accounts/{id}/config', [app\controller\ThirdPartyController::class, 'config']);
    Route::post('/third-party/game-accounts/{id}/logs', [app\controller\ThirdPartyController::class, 'appendLogs']);
    Route::post('/third-party/apply-config', [app\controller\ThirdPartyController::class, 'applyConfig']);
});
