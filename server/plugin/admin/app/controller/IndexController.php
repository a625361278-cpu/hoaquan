<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\common\Util;
use plugin\admin\app\model\Option;
use plugin\admin\app\service\GameAssistUserStats;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use think\db\Where;
use Throwable;
use Workerman\Worker;

class IndexController
{

    /**
     * 无需登录的方法
     * @var string[]
     */
    protected $noNeedLogin = ['index'];

    /**
     * 不需要鉴权的方法
     * @var string[]
     */
    protected $noNeedAuth = ['dashboard'];

    /**
     * 后台主页
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function index(Request $request): Response
    {
        clearstatcache();
        if (!is_file(base_path('plugin/admin/config/database.php'))) {
            return raw_view('index/install');
        }
        $admin = admin();
        if (!$admin) {
            $name = 'system_config';
            $config = Option::where('name', $name)->value('value');
            $config = json_decode($config, true);
            $title = $config['logo']['title'] ?? 'webman admin';
            $logo = $config['logo']['image'] ?? '/app/admin/admin/images/logo.png';
            return raw_view('account/login',['logo'=>$logo,'title'=>$title]);
        }
        return raw_view('index/index');
    }

    /**
     * 仪表板
     * @param Request $request
     * @return Response
     * @throws Throwable
     */
    public function dashboard(Request $request): Response
    {
        $userStats = (new GameAssistUserStats())->summarize();
        // mysql版本
        $version = Util::db()->select('select VERSION() as version');
        $mysql_version = $version[0]->version ?? 'unknown';

        return raw_view('index/dashboard', [
            'today_user_count' => $userStats['today_user_count'],
            'day7_user_count' => $userStats['day7_user_count'],
            'day30_user_count' => $userStats['day30_user_count'],
            'user_count' => $userStats['user_count'],
            'php_version' => PHP_VERSION,
            'workerman_version' =>  Worker::VERSION,
            'webman_version' => Util::getPackageVersion('workerman/webman-framework'),
            'admin_version' => Util::getPackageVersion('webman/admin'),
            'mysql_version' => $mysql_version,
            'os' => PHP_OS,
            'day7_detail' => $userStats['day7_detail'],
        ]);
    }

}
