#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use support\Db;

try {
    $schema = Db::schema();

    if (!$schema->hasColumn('ga_users', 'email')) {
        $schema->table('ga_users', function ($table) {
            $table->string('email', 128)->nullable()->after('account')->unique('uniq_email');
        });
    }

    $settings = [
        'smtp_enabled' => ['0', 'SMTP是否启用：0否，1是'],
        'smtp_host' => ['', 'SMTP服务器地址'],
        'smtp_port' => ['587', 'SMTP端口'],
        'smtp_username' => ['', 'SMTP账号'],
        'smtp_password' => ['', 'SMTP密码或授权码'],
        'smtp_encryption' => ['tls', 'SMTP加密方式：tls、ssl、none'],
        'smtp_from_email' => ['', '发件邮箱'],
        'smtp_from_name' => ['Hoa Quán', '发件名称'],
    ];

    foreach ($settings as $name => [$value, $remark]) {
        Db::table('ga_system_settings')->updateOrInsert(
            ['name' => $name],
            ['value' => $value, 'remark' => $remark]
        );
    }

    echo '业务数据库结构同步完成' . PHP_EOL;
    echo 'ga_users.email：已存在' . PHP_EOL;
    echo 'SMTP配置项：已同步' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '业务数据库结构同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
