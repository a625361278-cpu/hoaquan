<?php

namespace tests\Feature;

use app\exception\ApiException;
use app\service\GatewayThirdPartyScriptRuntime;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayThirdPartyScriptConnectionStore;

class ThirdPartyScriptRuntimeTest extends TestCase
{
    public function testStartAccountUsesIdleConnectionAndPayloadHasNoAccountId(): void
    {
        $store = new ArrayThirdPartyScriptConnectionStore();
        $store->registerIdle('client-1', ['remote_ip' => '127.0.0.1']);
        $sent = [];
        $sessions = [];
        $runtime = new GatewayThirdPartyScriptRuntime(
            $store,
            sender: function (string $clientId, string $payload) use (&$sent): bool {
                $sent[$clientId][] = json_decode($payload, true);
                return true;
            },
            sessionUpdater: function (string $clientId, array $session) use (&$sessions): void {
                $sessions[$clientId] = $session;
            }
        );

        $result = $runtime->startAccount([
            'id' => 3,
            'game_username' => 'player001',
        ], 'request-1', 'session-1', 'secret-password', ['basic' => ['debug' => true]], [
            'exists' => true,
            'state' => (object)['step' => 2],
            'saved_at' => '2026-07-09 12:00:00',
        ]);

        $this->assertSame('client-1', $result['client_id']);
        $payload = $sent['client-1'][0];
        $this->assertSame('start', $payload['type']);
        $this->assertSame('request-1', $payload['request_id']);
        $this->assertSame('session-1', $payload['session_id']);
        $this->assertSame('player001', $payload['game_username']);
        $this->assertSame('secret-password', $payload['game_password']);
        $this->assertTrue($payload['task_state']['exists']);
        $this->assertSame(2, $payload['task_state']['state']['step']);
        $this->assertSame('2026-07-09 12:00:00', $payload['task_state']['saved_at']);
        $this->assertArrayNotHasKey('account_id', $payload);
        $this->assertSame(3, $sessions['client-1']['account_id']);
        $this->assertSame('bound', $store->connection('client-1')['state']);
    }

    public function testStartPayloadIncludesExplicitEmptyTaskStateByDefault(): void
    {
        $store = new ArrayThirdPartyScriptConnectionStore();
        $store->registerIdle('client-1');
        $sent = [];
        $runtime = new GatewayThirdPartyScriptRuntime(
            $store,
            sender: function (string $clientId, string $payload) use (&$sent): bool {
                $sent[$clientId][] = json_decode($payload, true);
                return true;
            },
            sessionUpdater: static function (): void {
            }
        );

        $runtime->startAccount(['id' => 3, 'game_username' => 'player001'], 'request-1', 'session-1', 'secret-password', []);

        $payload = $sent['client-1'][0];
        $this->assertFalse($payload['task_state']['exists']);
        $this->assertSame([], $payload['task_state']['state']);
        $this->assertNull($payload['task_state']['saved_at']);
    }

    public function testStartFailsWhenNoIdleConnection(): void
    {
        $runtime = new GatewayThirdPartyScriptRuntime(new ArrayThirdPartyScriptConnectionStore());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('服务器未准备好，请联系管理员');

        $runtime->startAccount(['id' => 3], 'request-1', 'session-1', 'secret-password', []);
    }

    public function testStopSendsStopWithoutAccountIdAndMarksStopping(): void
    {
        $store = new ArrayThirdPartyScriptConnectionStore();
        $store->registerIdle('client-1');
        $store->allocateIdle(3, 'session-1', 'request-1');
        $sent = [];
        $runtime = new GatewayThirdPartyScriptRuntime(
            $store,
            sender: function (string $clientId, string $payload) use (&$sent): bool {
                $sent[$clientId][] = json_decode($payload, true);
                return true;
            },
            sessionUpdater: static function (): void {
            }
        );

        $result = $runtime->stopAccount(3, 'stop-1');

        $this->assertTrue($result['sent']);
        $payload = $sent['client-1'][0];
        $this->assertSame('stop', $payload['type']);
        $this->assertSame('stop-1', $payload['request_id']);
        $this->assertSame('session-1', $payload['session_id']);
        $this->assertArrayNotHasKey('account_id', $payload);
        $this->assertSame('stopping', $store->connection('client-1')['state']);
    }
}
