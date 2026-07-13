<?php

namespace tests\Feature;

use app\service\GameLogMessage;
use PHPUnit\Framework\TestCase;

class GameLogMessageTest extends TestCase
{
    public function testLocalizedMessageKeepsLevelAndStructuredTranslationPayload(): void
    {
        $line = GameLogMessage::localized('warn', 'client.logs.system.auto_reconnect_retry_later', [
            'error' => 'connection timeout',
        ]);

        $this->assertStringStartsWith('[WARN] ' . GameLogMessage::PREFIX, $line);
        $payload = json_decode(substr($line, strlen('[WARN] ' . GameLogMessage::PREFIX)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('client.logs.system.auto_reconnect_retry_later', $payload['key']);
        $this->assertSame(['error' => 'connection timeout'], $payload['params']);
    }

    public function testLocalizedMessageRejectsKeysOutsideUserLogNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GameLogMessage::localized('INFO', 'api.game.started');
    }
}
