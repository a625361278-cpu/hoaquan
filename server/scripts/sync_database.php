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

    $userColumns = [
        'security_question_key' => static fn ($table) => $table->string('security_question_key', 64)->nullable()->after('email')->comment('密保问题标识'),
        'security_answer_hash' => static fn ($table) => $table->string('security_answer_hash', 255)->nullable()->after('security_question_key')->comment('密保答案哈希'),
        'invite_code' => static fn ($table) => $table->string('invite_code', 16)->nullable()->after('expire_at')->unique('uniq_invite_code')->comment('专属邀请码'),
        'invited_by_user_id' => static fn ($table) => $table->unsignedInteger('invited_by_user_id')->nullable()->after('invite_code')->index('idx_invited_by_user_id')->comment('邀请人用户ID'),
        'invite_registered_ip' => static fn ($table) => $table->string('invite_registered_ip', 64)->default('')->after('invited_by_user_id')->comment('邀请注册IP'),
        'bound_role_id' => static fn ($table) => $table->string('bound_role_id', 128)->nullable()->after('invite_registered_ip')->unique('uniq_bound_role_id')->comment('已绑定游戏角色ID'),
        'role_bound_at' => static fn ($table) => $table->dateTime('role_bound_at')->nullable()->after('bound_role_id')->comment('角色绑定时间'),
        'invite_rewarded_at' => static fn ($table) => $table->dateTime('invite_rewarded_at')->nullable()->after('role_bound_at')->comment('邀请奖励发放时间'),
    ];

    foreach ($userColumns as $column => $addColumn) {
        if (!$schema->hasColumn('ga_users', $column)) {
            $schema->table('ga_users', $addColumn);
        }
    }

    $gameAccountColumns = [
        'game_username' => static fn ($table) => $table->string('game_username', 128)->default('')->after('display_name')->comment('游戏登录账号'),
        'game_password_cipher' => static fn ($table) => $table->text('game_password_cipher')->nullable()->after('game_username')->comment('游戏密码密文'),
        'channel_code' => static fn ($table) => $table->string('channel_code', 64)->default('official_app')->after('game_username')->comment('渠道标识'),
        'server_id' => static fn ($table) => $table->string('server_id', 64)->default('')->after('channel_code')->comment('区服ID'),
        'server_name' => static fn ($table) => $table->string('server_name', 128)->default('')->after('server_id')->comment('区服名称'),
        'sync_status' => static fn ($table) => $table->string('sync_status', 32)->default('local_unsynced')->after('status')->comment('同步状态'),
        'third_party_account_id' => static fn ($table) => $table->string('third_party_account_id', 128)->default('')->after('sync_status')->comment('第三方账号标识'),
        'log_session_id' => static fn ($table) => $table->string('log_session_id', 64)->default('')->after('third_party_account_id')->comment('当前运行日志会话'),
        'expire_time' => static fn ($table) => $table->dateTime('expire_time')->nullable()->after('log_session_id')->comment('游戏账号到期时间'),
        'config_json' => static fn ($table) => $table->json('config_json')->nullable()->after('remark')->comment('本地配置JSON'),
    ];

    foreach ($gameAccountColumns as $column => $addColumn) {
        if (!$schema->hasColumn('ga_game_accounts', $column)) {
            $schema->table('ga_game_accounts', $addColumn);
        }
    }

    if (!$schema->hasTable('ga_game_account_logs')) {
        $schema->create('ga_game_account_logs', function ($table) {
            $table->bigIncrements('id')->comment('日志ID');
            $table->unsignedInteger('game_account_id')->comment('游戏账号ID');
            $table->unsignedBigInteger('line_no')->comment('账号内日志行号');
            $table->text('message')->comment('日志内容');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->unique(['game_account_id', 'line_no'], 'uniq_account_line');
            $table->index('game_account_id', 'idx_game_account_id');
        });
    }

    if (!$schema->hasTable('ga_announcements')) {
        $schema->create('ga_announcements', function ($table) {
            $table->increments('id')->comment('公告ID');
            $table->string('title_zh_cn', 128)->comment('中文标题');
            $table->string('title_vi', 128)->comment('越南文标题');
            $table->text('content_zh_cn')->comment('中文正文');
            $table->text('content_vi')->comment('越南文正文');
            $table->tinyInteger('status')->default(0)->comment('状态：1启用，0停用');
            $table->dateTime('published_at')->comment('发布时间');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->index(['status', 'published_at'], 'idx_status_published');
        });
    } else {
        $announcementColumns = [
            'title_zh_cn' => static fn ($table) => $table->string('title_zh_cn', 128)->comment('中文标题'),
            'title_vi' => static fn ($table) => $table->string('title_vi', 128)->comment('越南文标题'),
            'content_zh_cn' => static fn ($table) => $table->text('content_zh_cn')->comment('中文正文'),
            'content_vi' => static fn ($table) => $table->text('content_vi')->comment('越南文正文'),
            'status' => static fn ($table) => $table->tinyInteger('status')->default(0)->comment('状态：1启用，0停用'),
            'published_at' => static fn ($table) => $table->dateTime('published_at')->comment('发布时间'),
            'created_at' => static fn ($table) => $table->dateTime('created_at')->useCurrent()->comment('创建时间'),
            'updated_at' => static fn ($table) => $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间'),
        ];

        foreach ($announcementColumns as $column => $addColumn) {
            if (!$schema->hasColumn('ga_announcements', $column)) {
                $schema->table('ga_announcements', $addColumn);
            }
        }
    }

    if (!$schema->hasTable('ga_user_point_transactions')) {
        $schema->create('ga_user_point_transactions', function ($table) {
            $table->bigIncrements('id')->comment('流水ID');
            $table->unsignedInteger('user_id')->comment('用户ID');
            $table->string('type', 32)->comment('类型');
            $table->decimal('amount', 10, 2)->comment('变动点数');
            $table->decimal('balance_after', 10, 2)->comment('变动后余额');
            $table->string('description', 255)->default('')->comment('说明');
            $table->unsignedInteger('related_user_id')->nullable()->comment('关联用户ID');
            $table->string('related_role_id', 128)->default('')->comment('关联角色ID');
            $table->string('ip_address', 64)->default('')->comment('触发IP');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index(['type', 'created_at'], 'idx_type_created');
            $table->unique(['type', 'related_user_id'], 'uniq_invite_reward_user');
        });
    } elseif (!$schema->hasIndex('ga_user_point_transactions', 'uniq_invite_reward_user')) {
        $schema->table('ga_user_point_transactions', function ($table) {
            $table->unique(['type', 'related_user_id'], 'uniq_invite_reward_user');
        });
    }

    $settings = [
        'third_party_enabled' => ['0', '第三方接口是否启用：0否，1是'],
        'third_party_base_url' => ['', '第三方接口地址'],
        'third_party_sign_secret' => ['', '第三方签名密钥'],
        'third_party_ws_url' => ['', '第三方WebSocket地址'],
        'third_party_ws_urls' => ['', '第三方WebSocket连接池地址列表，每行一个连接槽位'],
        'third_party_ws_connection_capacity' => ['10', '第三方单条WebSocket连接最大承载账号数'],
        'third_party_transport' => ['websocket', '第三方通信方式：websocket或http'],
        'game_account_credential_key' => [app_env('GAME_ACCOUNT_CREDENTIAL_KEY', ''), '游戏账号密码加密密钥'],
        'auth_verification_mode' => ['security_question', '认证方式：security_question密保问题，email_code邮箱验证码'],
        'smtp_enabled' => ['0', 'SMTP是否启用：0否，1是'],
        'smtp_host' => ['', 'SMTP服务器地址'],
        'smtp_port' => ['587', 'SMTP端口'],
        'smtp_username' => ['', 'SMTP账号'],
        'smtp_password' => ['', 'SMTP密码或授权码'],
        'smtp_encryption' => ['tls', 'SMTP加密方式：tls、ssl、none'],
        'smtp_from_email' => ['', '发件邮箱'],
        'smtp_from_name' => ['Hoa Quán', '发件名称'],
        'invite_daily_limit' => ['50', '同一邀请人每日邀请奖励上限'],
        'invite_same_ip_daily_limit' => ['3', '同一邀请人同IP每日邀请奖励风控上限'],
    ];

    foreach ($settings as $name => [$value, $remark]) {
        $exists = Db::table('ga_system_settings')->where('name', $name)->exists();
        if ($exists) {
            Db::table('ga_system_settings')
                ->where('name', $name)
                ->update(['remark' => $remark]);
            continue;
        }

        Db::table('ga_system_settings')->insert([
            'name' => $name,
            'value' => $value,
            'remark' => $remark,
        ]);
    }

    echo '业务数据库结构同步完成' . PHP_EOL;
    echo 'ga_users.email：已存在' . PHP_EOL;
    echo 'ga_users密保字段：已同步' . PHP_EOL;
    echo 'ga_game_accounts：预览账号与配置列已同步' . PHP_EOL;
    echo 'ga_game_account_logs：已同步' . PHP_EOL;
    echo 'ga_announcements：已同步' . PHP_EOL;
    echo 'ga_user_point_transactions：已同步' . PHP_EOL;
    echo 'SMTP配置项：已同步' . PHP_EOL;
    echo '认证方式配置项：已同步' . PHP_EOL;
    echo '邀请奖励配置项：已同步' . PHP_EOL;
    echo '第三方WebSocket连接池配置项：已同步' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '业务数据库结构同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
