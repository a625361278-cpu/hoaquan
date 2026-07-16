<?php

namespace tests\Feature;

use app\service\GameConfigVisibilityService;
use app\service\SystemSettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GameConfigVisibilityServiceTest extends TestCase
{
    public function testFormalDefaultsHideExactlyThirtyThreeItems(): void
    {
        $service = new GameConfigVisibilityService($this->settings('{}'));

        $visibility = $service->visibilityByPath();
        $hidden = $service->hiddenPaths();

        $this->assertCount(196, $visibility);
        $this->assertCount(33, $hidden);
        $this->assertFalse($visibility['activity.flowerLetter.enabled']);
        $this->assertFalse($visibility['activity.card.reward']);
        $this->assertFalse($visibility['plant.elves.plant']);
        $this->assertFalse($visibility['plant.elves.selectedIds']);
        $this->assertFalse($visibility['plant.elves.speedupDispatch']);
        $this->assertFalse($visibility['union.energyForest.collect']);
        $this->assertTrue($visibility['basic.debug']);
        $this->assertSame(163, count(array_filter($visibility)));

        $catalog = $service->adminCatalog();
        $hiddenByTab = [];
        foreach ($catalog['tabs'] as $tab) {
            $hiddenByTab[$tab['key']] = count(array_filter(
                array_merge(...array_map(static fn (array $group): array => $group['items'], $tab['groups'])),
                static fn (array $item): bool => !$item['default_visible']
            ));
        }
        $this->assertSame(29, $hiddenByTab['activity']);
        $this->assertSame(3, $hiddenByTab['plant']);
        $this->assertSame(1, $hiddenByTab['union']);
        $this->assertSame(0, $hiddenByTab['basic']);
        $this->assertSame(0, $hiddenByTab['order']);
    }

    public function testStoredOverridesChangeOnlyTheirPaths(): void
    {
        $overrides = json_encode([
            'plant.elves.plant' => true,
            'basic.debug' => false,
        ], JSON_THROW_ON_ERROR);
        $service = new GameConfigVisibilityService($this->settings($overrides));

        $visibility = $service->visibilityByPath();

        $this->assertTrue($visibility['plant.elves.plant']);
        $this->assertFalse($visibility['basic.debug']);
        $this->assertFalse($visibility['plant.elves.selectedIds']);
        $this->assertCount(33, $service->hiddenPaths());
    }

    public function testSavingFullMapStoresOnlyOverrides(): void
    {
        $settings = $this->settings('{}');
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with($this->callback(static function (array $saved): bool {
                $decoded = json_decode($saved[GameConfigVisibilityService::SETTING_NAME], true, 512, JSON_THROW_ON_ERROR);
                return $decoded === [
                    'basic.debug' => false,
                    'plant.elves.plant' => true,
                ];
            }));
        $service = new GameConfigVisibilityService($settings);
        $visibility = $service->visibilityByPath();
        $visibility['basic.debug'] = false;
        $visibility['plant.elves.plant'] = true;

        $service->saveVisibility($visibility);
    }

    public function testSavingFormalDefaultsStoresEmptyJsonObject(): void
    {
        $settings = $this->settings('{}');
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with([GameConfigVisibilityService::SETTING_NAME => '{}']);
        $service = new GameConfigVisibilityService($settings);

        $service->saveVisibility($service->visibilityByPath());
    }

    #[DataProvider('invalidStoredSettings')]
    public function testInvalidStoredSettingIsRejected(string $stored): void
    {
        $service = new GameConfigVisibilityService($this->settings($stored));

        $this->expectException(\RuntimeException::class);
        $service->visibilityByPath();
    }

    public static function invalidStoredSettings(): array
    {
        return [
            'damaged json' => ['{"basic.debug":'],
            'json list' => ['[]'],
            'unknown path' => ['{"unknown.path":true}'],
            'non boolean value' => ['{"basic.debug":1}'],
        ];
    }

    public function testIncompleteSavePayloadIsRejected(): void
    {
        $service = new GameConfigVisibilityService($this->settings('{}'));

        $this->expectException(\RuntimeException::class);
        $service->saveVisibility(['basic.debug' => true]);
    }

    public function testVisibleDependentWithHiddenControllerIsRejected(): void
    {
        $service = new GameConfigVisibilityService($this->settings('{}'));
        $visibility = $service->visibilityByPath();
        $visibility['plant.friendSteal.enabled'] = false;

        $this->expectException(\RuntimeException::class);
        $service->saveVisibility($visibility);
    }

    public function testStoredOrphanedDependentIsRejected(): void
    {
        $service = new GameConfigVisibilityService($this->settings(json_encode([
            'plant.friendSteal.enabled' => false,
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(\RuntimeException::class);
        $service->visibilityByPath();
    }

    public function testAdminCatalogIsLocalizedAndComplete(): void
    {
        $catalog = (new GameConfigVisibilityService($this->settings('{}'), 'vi'))->adminCatalog();

        $this->assertSame(196, $catalog['item_count']);
        $this->assertSame(163, $catalog['visible_count']);
        $this->assertSame(33, $catalog['hidden_count']);
        $this->assertSame('Cơ bản', $catalog['tabs'][0]['title']);
        $this->assertNotSame($catalog['tabs'][0]['groups'][0]['items'][0]['label_key'], $catalog['tabs'][0]['groups'][0]['items'][0]['label']);
    }

    private function settings(string $stored): SystemSettingService&MockObject
    {
        $settings = $this->createMock(SystemSettingService::class);
        $settings->method('get')->willReturn($stored);
        return $settings;
    }
}
