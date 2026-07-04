<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\service\ThirdPartyConnectionAdminService;
use RuntimeException;
use support\exception\BusinessException;
use support\Request;
use support\Response;

class GameAssistThirdPartyConnectionController extends Base
{
    private ThirdPartyConnectionAdminService $service;

    public function __construct()
    {
        $this->service = new ThirdPartyConnectionAdminService(locale: I18n::localeFromRequest());
    }

    public function index(): Response
    {
        return raw_view('game-assist-third-party-connection/index');
    }

    public function select(): Response
    {
        $rows = $this->service->listSlots();
        return json(['code' => 0, 'msg' => 'ok', 'count' => count($rows), 'data' => $rows]);
    }

    public function start(Request $request): Response
    {
        $slotId = (string)$request->post('slot_id', '');
        $this->runCommand(fn () => $this->service->startSlot($slotId));
        return $this->json(0);
    }

    public function stop(Request $request): Response
    {
        $slotId = (string)$request->post('slot_id', '');
        $this->runCommand(fn () => $this->service->stopSlot($slotId));
        return $this->json(0);
    }

    public function startAll(): Response
    {
        $this->runCommand(fn () => $this->service->startAllSlots());
        return $this->json(0);
    }

    public function stopAll(): Response
    {
        $this->runCommand(fn () => $this->service->stopAllSlots());
        return $this->json(0);
    }

    private function runCommand(callable $command): void
    {
        try {
            $command();
        } catch (RuntimeException $exception) {
            throw new BusinessException($exception->getMessage(), 2);
        }
    }
}
