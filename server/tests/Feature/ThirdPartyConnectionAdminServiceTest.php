<?php

namespace tests\Feature;

use app\service\SystemSettingService;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\ThirdPartyConnectionAdminService;
use RuntimeException;
use tests\Support\ArrayThirdPartyCommandQueue;

class ThirdPartyConnectionAdminServiceTest extends TestCase
{
    public function testListSlotsUsesConfiguredUrlsAndOverlaysRuntimeState(): void
    {
        $queue = new ArrayThirdPartyCommandQueue();
        $queue->writeSlotState('slot-2', [
            'slot_id' => 'slot-2',
            'url' => 'ws://third-party/b',
            'state' => 'connected',
            'account_ids' => [9, 3],
            'account_count' => 2,
            'capacity' => 5,
            'last_error' => '',
            'updated_at' => 1783123200,
        ]);

        $service = new ThirdPartyConnectionAdminService($this->settings([
            'ws://third-party/a',
            'ws://third-party/b',
        ], capacity: 5), $queue);

        $rows = $service->listSlots();

        $this->assertCount(2, $rows);
        $this->assertSame('slot-1', $rows[0]['slot_id']);
        $this->assertSame('disconnected', $rows[0]['state']);
        $this->assertSame('slot-2', $rows[1]['slot_id']);
        $this->assertSame('connected', $rows[1]['state']);
        $this->assertSame([3, 9], $rows[1]['account_ids']);
        $this->assertSame('3, 9', $rows[1]['account_ids_text']);
        $this->assertSame(5, $rows[1]['capacity']);
        $this->assertSame(1783123200, $rows[1]['updated_at']);
        $this->assertNotSame('', $rows[1]['updated_at_text']);
    }

    public function testStartAndStopSlotEnqueueSlotCommands(): void
    {
        $queue = new ArrayThirdPartyCommandQueue();
        $service = new ThirdPartyConnectionAdminService($this->settings(['ws://third-party/a']), $queue);

        $service->startSlot('slot-1');
        $service->stopSlot('slot-1');
        $service->startAllSlots();
        $service->stopAllSlots();

        $this->assertSame([
            'start_slot',
            'stop_slot',
            'start_all_slots',
            'stop_all_slots',
        ], array_column($queue->commands, 'action'));
        $this->assertSame('slot-1', $queue->commands[0]['slot_id']);
        $this->assertTrue($queue->commands[1]['force']);
        $this->assertTrue($queue->commands[3]['force']);
    }

    public function testStartSlotRejectsDisabledThirdParty(): void
    {
        $service = new ThirdPartyConnectionAdminService($this->settings(['ws://third-party/a'], enabled: false), new ArrayThirdPartyCommandQueue());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('第三方接口未启用');

        $service->startSlot('slot-1');
    }

    public function testStartSlotRejectsUnknownSlot(): void
    {
        $service = new ThirdPartyConnectionAdminService($this->settings(['ws://third-party/a']), new ArrayThirdPartyCommandQueue());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('槽位不存在');

        $service->startSlot('slot-2');
    }

    private function settings(array $urls, int $capacity = 10, bool $enabled = true): SystemSettingService
    {
        return new class($urls, $capacity, $enabled) extends SystemSettingService {
            public function __construct(private array $urls, private int $capacity, private bool $enabled)
            {
            }

            public function thirdPartyConfig(): array
            {
                return [
                    'enabled' => $this->enabled,
                    'ws_url' => $this->urls[0] ?? '',
                    'ws_urls' => $this->urls,
                    'ws_connection_capacity' => $this->capacity,
                    'credential_key' => 'test-key',
                ];
            }
        };
    }
}
