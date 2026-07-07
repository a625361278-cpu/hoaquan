<?php

namespace plugin\admin\app\controller;

use app\support\I18n;
use plugin\admin\app\service\ThirdPartyConnectionAdminService;
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
        $rows = $this->service->listConnections();
        return json([
            'code' => 0,
            'msg' => 'ok',
            'count' => count($rows),
            'data' => $rows,
            'summary' => $this->service->summary(),
        ]);
    }
}
