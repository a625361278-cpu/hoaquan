#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use plugin\admin\api\Menu;
use plugin\admin\app\model\Option;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\Rule;
use support\Db;

const GAMEASSIST_ADMIN_TITLE = 'Hoa Quán 后台';
const GAMEASSIST_ADMIN_LOGO = '/app/admin/admin/images/gameassist-logo.svg';

try {
    $menuFile = base_path('plugin/admin/config/menu.php');
    $menus = require $menuFile;
    if (!is_array($menus) || $menus === []) {
        throw new RuntimeException("后台菜单配置异常：{$menuFile}");
    }

    Menu::import($menus);

    $option = Option::where('name', 'system_config')->first();
    if (!$option) {
        $defaultConfigFile = base_path('plugin/admin/public/config/pear.config.json');
        $defaultConfig = file_get_contents($defaultConfigFile);
        if ($defaultConfig === false || $defaultConfig === '') {
            throw new RuntimeException("后台默认配置读取失败：{$defaultConfigFile}");
        }

        $option = new Option();
        $option->name = 'system_config';
        $option->value = $defaultConfig;
    }

    $config = json_decode((string)$option->value, true);
    if (!is_array($config)) {
        throw new RuntimeException('后台 system_config 不是合法 JSON，已停止同步');
    }

    $config['logo'] ??= [];
    if (!is_array($config['logo'])) {
        throw new RuntimeException('后台 system_config.logo 结构异常，已停止同步');
    }

    $config['logo']['title'] = GAMEASSIST_ADMIN_TITLE;
    $config['logo']['image'] = GAMEASSIST_ADMIN_LOGO;
    $option->value = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $option->save();

    $adminRoleIds = Db::table('wa_admin_roles')->where('admin_id', 1)->pluck('role_id')->toArray();
    if ($adminRoleIds === []) {
        throw new RuntimeException('后台 admin 账号没有绑定角色，已停止同步');
    }

    Role::whereIn('id', $adminRoleIds)->update(['rules' => '*']);

    $ruleCount = Rule::count();
    $rootKeys = Rule::whereIn('key', ['database', 'auth', 'user', 'common'])->pluck('key')->toArray();
    sort($rootKeys);

    echo '后台品牌和菜单同步完成' . PHP_EOL;
    echo '菜单数量：' . $ruleCount . PHP_EOL;
    echo '关键菜单：' . implode(', ', $rootKeys) . PHP_EOL;
    echo '后台名称：' . GAMEASSIST_ADMIN_TITLE . PHP_EOL;
    echo '后台图标：' . GAMEASSIST_ADMIN_LOGO . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '后台品牌和菜单同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
