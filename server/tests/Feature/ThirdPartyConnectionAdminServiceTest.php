<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;
use plugin\admin\app\service\ThirdPartyConnectionAdminService;
use tests\Support\ArrayGameLogQueue;
use tests\Support\ArrayThirdPartyScriptConnectionStore;

class ThirdPartyConnectionAdminServiceTest extends TestCase
{
    public function testListConnectionsReturnsScriptPoolStateAndSummary(): void
    {
        $store = new ArrayThirdPartyScriptConnectionStore();
        $store->registerIdle('client-1', [
            'remote_ip' => '127.0.0.1',
            'script_version' => '1.0.0',
        ]);
        $store->registerIdle('client-2', [
            'remote_ip' => '127.0.0.2',
            'script_version' => '1.0.1',
        ]);
        $store->allocateIdle(3, 'session-1', 'request-1');
        $store->markStopping(3);

        $logQueue = new ArrayGameLogQueue();
        $logQueue->enqueueNormal(3, ['line'], 'session-1');
        $service = new ThirdPartyConnectionAdminService($store, $logQueue);
        $rows = $service->listConnections();
        $summary = $service->summary();

        $this->assertCount(2, $rows);
        $this->assertSame(2, $summary['online_count']);
        $this->assertSame(1, $summary['idle_count']);
        $this->assertSame(0, $summary['bound_count']);
        $this->assertSame(1, $summary['stopping_count']);
        $this->assertSame(1, $summary['log_queue']['total_pending']);
        $this->assertSame(1, $summary['log_queue']['max_shard_pending']);
        $this->assertSame('client-1', $rows[0]['client_id']);
        $this->assertSame('3', $rows[0]['account_id_text']);
        $this->assertSame('stopping', $rows[0]['state']);
    }
}
