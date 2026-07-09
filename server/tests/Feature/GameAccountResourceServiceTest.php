<?php

namespace tests\Feature;

use app\service\GameAccountResourceService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tests\Support\ArrayGameAccountRuntimeResourceStore;

class GameAccountResourceServiceTest extends TestCase
{
    public function testAccountWithoutStatusSnapshotReturnsDefaultResources(): void
    {
        $service = new GameAccountResourceService(new ArrayGameAccountRuntimeResourceStore());

        $resources = $service->resourcesForAccount(3);

        $this->assertSame(0, $resources['level']);
        $this->assertSame(0, $resources['water']);
        $this->assertSame(0, $resources['diamond']);
        $this->assertSame(0, $resources['coin']);
        $this->assertSame('0/0', $resources['raceCoin']);
    }

    public function testStatusPayloadSavesRealResourcesWithProtocolFieldNames(): void
    {
        $store = new ArrayGameAccountRuntimeResourceStore();
        $service = new GameAccountResourceService($store);

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'level' => 14,
            'water' => 1,
            'diamond' => 754,
            'coin' => 236000,
        ]);

        $this->assertSame(14, $result['resources']['level']);
        $this->assertSame(1, $result['resources']['water']);
        $this->assertSame(754, $result['resources']['diamond']);
        $this->assertSame(236000, $result['resources']['coin']);
        $this->assertSame(0, $result['resources']['speedCard']);
        $this->assertSame([
            'level' => 14,
            'water' => 1,
            'diamond' => 754,
            'coin' => 236000,
        ], $store->snapshots[3]['resources']);
    }

    public function testNestedResourcesUseExactProtocolFieldNames(): void
    {
        $service = new GameAccountResourceService(new ArrayGameAccountRuntimeResourceStore());

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'resources' => [
                'speedCard' => 6,
                'hireBook' => 2,
                'floralCoin' => 15,
                'meowCoin' => 9,
                'raceCoin' => '3/5',
                'flowerFinish' => 7,
                'satinFinish' => 8,
                'decorateFinish' => 4,
                'customerFinish' => 11,
            ],
        ]);

        $this->assertSame(6, $result['resources']['speedCard']);
        $this->assertSame(2, $result['resources']['hireBook']);
        $this->assertSame(15, $result['resources']['floralCoin']);
        $this->assertSame(9, $result['resources']['meowCoin']);
        $this->assertSame('3/5', $result['resources']['raceCoin']);
        $this->assertSame(7, $result['resources']['flowerFinish']);
        $this->assertSame(8, $result['resources']['satinFinish']);
        $this->assertSame(4, $result['resources']['decorateFinish']);
        $this->assertSame(11, $result['resources']['customerFinish']);
    }

    public function testEachStatusPayloadReplacesPreviousSnapshotInsteadOfMerging(): void
    {
        $store = new ArrayGameAccountRuntimeResourceStore();
        $service = new GameAccountResourceService($store);
        $service->saveStatusPayload(3, [
            'type' => 'status',
            'level' => 14,
            'speedCard' => 6,
        ]);

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'water' => 2,
        ]);

        $this->assertSame(0, $result['resources']['level']);
        $this->assertSame(2, $result['resources']['water']);
        $this->assertSame(0, $result['resources']['speedCard']);
        $this->assertSame(['water' => 2], $store->snapshots[3]['resources']);
    }

    public function testUnknownStatusKeysAreReportedWithoutBeingSaved(): void
    {
        $store = new ArrayGameAccountRuntimeResourceStore();
        $service = new GameAccountResourceService($store);

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'level' => 14,
            'bad_field' => 99,
        ]);

        $this->assertSame(['bad_field'], $result['unknown_keys']);
        $this->assertSame(['level' => 14], $store->snapshots[3]['resources']);
    }

    public function testStatusPayloadIgnoresProtocolMetadata(): void
    {
        $service = new GameAccountResourceService(new ArrayGameAccountRuntimeResourceStore());

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'request_id' => 'req-1',
            'session_id' => 'session-1',
            'script_version' => '1.0.0',
            'level' => 14,
        ]);

        $this->assertSame([], $result['unknown_keys']);
        $this->assertSame(14, $result['resources']['level']);
    }

    public function testGoldIsAcceptedAsCoinAlias(): void
    {
        $store = new ArrayGameAccountRuntimeResourceStore();
        $service = new GameAccountResourceService($store);

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'gold' => 236000,
        ]);

        $this->assertSame([], $result['unknown_keys']);
        $this->assertSame(['coin' => 236000], $store->snapshots[3]['resources']);
        $this->assertSame(236000, $result['resources']['coin']);
    }

    public function testMatchingGoldAndCoinAreAccepted(): void
    {
        $service = new GameAccountResourceService(new ArrayGameAccountRuntimeResourceStore());

        $result = $service->saveStatusPayload(3, [
            'type' => 'status',
            'coin' => 236000,
            'gold' => 236000,
        ]);

        $this->assertSame([], $result['unknown_keys']);
        $this->assertSame(236000, $result['resources']['coin']);
    }

    public function testConflictingGoldAndCoinAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('status fields coin and gold conflict');

        GameAccountResourceService::normalizeStatusPayload([
            'type' => 'status',
            'coin' => 236000,
            'gold' => 123,
        ]);
    }

    public function testStatusPayloadRejectsAccountIdAndStructuredKnownField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GameAccountResourceService::normalizeStatusPayload([
            'type' => 'status',
            'account_id' => 3,
        ]);
    }

    public function testStatusPayloadRejectsStructuredKnownField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GameAccountResourceService::normalizeStatusPayload([
            'type' => 'status',
            'level' => ['value' => 14],
        ]);
    }
}
