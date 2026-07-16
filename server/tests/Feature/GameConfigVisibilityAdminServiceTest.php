<?php

namespace tests\Feature;

use app\service\GameConfigVisibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\GameConfigVisibilityAdminService;

class GameConfigVisibilityAdminServiceTest extends TestCase
{
    public function testSavePassesStrictBooleanMapToVisibilityService(): void
    {
        $map = ['basic.debug' => true];
        $visibility = $this->createMock(GameConfigVisibilityService::class);
        $visibility->expects($this->once())->method('saveVisibility')->with($map);
        $service = new GameConfigVisibilityAdminService($visibility);

        $service->save(['visibility_json' => json_encode($map, JSON_THROW_ON_ERROR)]);
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadIsRejected(array $payload): void
    {
        $visibility = $this->createMock(GameConfigVisibilityService::class);
        $visibility->expects($this->never())->method('saveVisibility');
        $service = new GameConfigVisibilityAdminService($visibility);

        $this->expectException(\RuntimeException::class);
        $service->save($payload);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing' => [[]],
            'damaged json' => [['visibility_json' => '{']],
            'list instead of object' => [['visibility_json' => '[]']],
        ];
    }
}
