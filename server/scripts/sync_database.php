#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';

use support\Db;

function ensureTableEngine(string $table, string $engine): void
{
    $row = Db::selectOne(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
    $current = strtoupper((string)($row->ENGINE ?? ''));
    if ($current !== '' && $current !== strtoupper($engine)) {
        Db::statement("ALTER TABLE `{$table}` ENGINE={$engine}");
    }
}

function indexColumns(string $table, string $index): array
{
    $rows = Db::select(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
        [$table, $index]
    );
    return array_map(static fn ($row): string => (string)$row->COLUMN_NAME, $rows);
}

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
        'game_uid' => static fn ($table) => $table->string('game_uid', 128)->default('')->after('game_username')->comment('Facebook或Google游戏UID'),
        'game_password_cipher' => static fn ($table) => $table->text('game_password_cipher')->nullable()->after('game_username')->comment('游戏密码密文'),
        'game_token_cipher' => static fn ($table) => $table->longText('game_token_cipher')->nullable()->after('game_password_cipher')->comment('Facebook或Google Token密文'),
        'channel_code' => static fn ($table) => $table->string('channel_code', 64)->default('official_app')->after('game_username')->comment('渠道标识'),
        'login_method' => static fn ($table) => $table->unsignedTinyInteger('login_method')->default(1)->after('channel_code')->comment('登录方式：1账号密码，2Facebook，3Google'),
        'server_id' => static fn ($table) => $table->string('server_id', 64)->default('')->after('channel_code')->comment('区服ID'),
        'server_name' => static fn ($table) => $table->string('server_name', 128)->default('')->after('server_id')->comment('区服名称'),
        'sync_status' => static fn ($table) => $table->string('sync_status', 32)->default('local_unsynced')->after('status')->comment('同步状态'),
        'third_party_account_id' => static fn ($table) => $table->string('third_party_account_id', 128)->default('')->after('sync_status')->comment('第三方账号标识'),
        'log_session_id' => static fn ($table) => $table->string('log_session_id', 64)->default('')->after('third_party_account_id')->comment('当前运行日志会话'),
        'desired_running' => static fn ($table) => $table->tinyInteger('desired_running')->default(0)->after('log_session_id')->comment('用户期望运行：1继续运行，0停止'),
        'auto_restart_attempts' => static fn ($table) => $table->unsignedTinyInteger('auto_restart_attempts')->default(0)->after('desired_running')->comment('自动重连连续失败次数'),
        'auto_restart_next_at' => static fn ($table) => $table->dateTime('auto_restart_next_at')->nullable()->after('auto_restart_attempts')->comment('下次自动重连时间'),
        'auto_restart_last_error' => static fn ($table) => $table->text('auto_restart_last_error')->nullable()->after('auto_restart_next_at')->comment('最近自动重连错误'),
        'expire_time' => static fn ($table) => $table->dateTime('expire_time')->nullable()->after('auto_restart_last_error')->comment('游戏账号到期时间'),
        'config_json' => static fn ($table) => $table->json('config_json')->nullable()->after('remark')->comment('本地配置JSON'),
    ];

    foreach ($gameAccountColumns as $column => $addColumn) {
        if (!$schema->hasColumn('ga_game_accounts', $column)) {
            $schema->table('ga_game_accounts', $addColumn);
        }
    }

    if (!$schema->hasIndex('ga_game_accounts', 'idx_auto_restart_due')) {
        $schema->table('ga_game_accounts', function ($table) {
            $table->index(['desired_running', 'status', 'auto_restart_next_at'], 'idx_auto_restart_due');
        });
    }

    if ($schema->hasTable('ga_game_account_logs')) {
        $legacyLogCount = (int)Db::table('ga_game_account_logs')->count();
        if ($legacyLogCount > 0) {
            throw new RuntimeException("废弃日志表 ga_game_account_logs 仍有 {$legacyLogCount} 行，请先人工确认迁移或清理后再同步");
        }
        $schema->drop('ga_game_account_logs');
    }

    if (!$schema->hasTable('ga_game_account_log_segments')) {
        $schema->create('ga_game_account_log_segments', function ($table) {
            $table->bigIncrements('id')->comment('日志段ID');
            $table->unsignedInteger('game_account_id')->comment('游戏账号ID');
            $table->string('session_id', 64)->comment('运行日志会话ID');
            $table->unsignedInteger('segment_no')->comment('段序号');
            $table->unsignedBigInteger('start_line_no')->comment('起始行号');
            $table->unsignedBigInteger('end_line_no')->comment('结束行号');
            $table->unsignedSmallInteger('entry_count')->comment('段内日志条数');
            $table->longText('payload_json')->comment('日志段JSON');
            $table->dateTime('first_at')->comment('段首日志时间');
            $table->dateTime('last_at')->comment('段尾日志时间');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique(['game_account_id', 'session_id', 'segment_no'], 'uniq_account_session_segment');
            $table->index(['game_account_id', 'session_id', 'end_line_no'], 'idx_account_session_line');
        });
    }

    if (!$schema->hasTable('ga_game_account_event_segments')) {
        $schema->create('ga_game_account_event_segments', function ($table) {
            $table->bigIncrements('id')->comment('事件日志段ID');
            $table->unsignedInteger('game_account_id')->comment('游戏账号ID');
            $table->unsignedInteger('segment_no')->comment('段序号');
            $table->unsignedBigInteger('start_event_no')->comment('起始事件号');
            $table->unsignedBigInteger('end_event_no')->comment('结束事件号');
            $table->unsignedSmallInteger('entry_count')->comment('段内事件条数');
            $table->longText('payload_json')->comment('事件段JSON');
            $table->dateTime('first_at')->comment('段首事件时间');
            $table->dateTime('last_at')->comment('段尾事件时间');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique(['game_account_id', 'segment_no'], 'uniq_account_event_segment');
            $table->index(['game_account_id', 'end_event_no'], 'idx_account_event_no');
        });
    }

    if (!$schema->hasTable('ga_game_account_log_states')) {
        $schema->create('ga_game_account_log_states', function ($table) {
            $table->bigIncrements('id')->comment('日志游标ID');
            $table->unsignedInteger('game_account_id')->comment('游戏账号ID');
            $table->string('log_type', 16)->comment('日志类型：normal/event');
            $table->string('session_id', 64)->default('')->comment('普通日志运行会话，事件日志为空');
            $table->unsignedBigInteger('last_sequence')->default(0)->comment('最后写入行号或事件号');
            $table->unsignedInteger('entry_count')->default(0)->comment('当前保留条数');
            $table->unsignedInteger('last_segment_no')->default(0)->comment('最后段序号');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique(['game_account_id', 'log_type', 'session_id'], 'uniq_account_log_state');
            $table->index(['log_type', 'updated_at'], 'idx_type_updated');
        });
    }

    if (!$schema->hasTable('ga_game_account_task_states')) {
        $schema->create('ga_game_account_task_states', function ($table) {
            $table->bigIncrements('id')->comment('任务状态ID');
            $table->unsignedInteger('game_account_id')->comment('游戏账号ID');
            $table->longText('state_json')->comment('第三方任务状态JSON');
            $table->char('state_hash', 64)->comment('任务状态SHA256');
            $table->unsignedInteger('state_bytes')->comment('任务状态字节数');
            $table->dateTime('saved_at')->comment('第三方保存时间');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique('game_account_id', 'uniq_task_state_account');
            $table->index('updated_at', 'idx_task_state_updated');
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
            $table->unsignedBigInteger('related_payment_order_id')->nullable()->comment('关联支付订单ID');
            $table->string('ip_address', 64)->default('')->comment('触发IP');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index(['type', 'created_at'], 'idx_type_created');
            $table->unique(['type', 'related_user_id'], 'uniq_invite_reward_user');
        });
    } else {
        if (!$schema->hasColumn('ga_user_point_transactions', 'related_payment_order_id')) {
            $schema->table('ga_user_point_transactions', function ($table) {
                $table->unsignedBigInteger('related_payment_order_id')->nullable()->after('related_role_id')->comment('关联支付订单ID');
            });
        }
        if (!$schema->hasIndex('ga_user_point_transactions', 'uniq_invite_reward_user')) {
            $schema->table('ga_user_point_transactions', function ($table) {
                $table->unique(['type', 'related_user_id'], 'uniq_invite_reward_user');
            });
        }
    }
    if (!$schema->hasIndex('ga_user_point_transactions', 'uniq_payment_recharge')) {
        $schema->table('ga_user_point_transactions', function ($table) {
            $table->unique(['type', 'related_payment_order_id'], 'uniq_payment_recharge');
        });
    }
    ensureTableEngine('ga_user_point_transactions', 'InnoDB');

    if (!$schema->hasTable('ga_payment_orders')) {
        $schema->create('ga_payment_orders', function ($table) {
            $table->bigIncrements('id')->comment('支付订单ID');
            $table->unsignedInteger('user_id')->comment('用户ID');
            $table->string('provider', 32)->default('ronnypay')->comment('支付服务商');
            $table->string('package_code', 64)->comment('套餐快照');
            $table->decimal('points', 10, 2)->comment('到账点数快照');
            $table->char('currency', 3)->default('VND')->comment('币种');
            $table->decimal('total_fee', 18, 2)->comment('订单金额');
            $table->string('customer_name', 128)->comment('付款人姓名快照');
            $table->string('customer_mobile', 64)->comment('付款人手机号快照');
            $table->longText('bank_account')->comment('付款账号快照');
            $table->string('idempotency_key', 64)->comment('客户端幂等键');
            $table->string('merchant_order', 64)->comment('商户订单号');
            $table->string('provider_order_number', 64)->nullable()->comment('平台订单号');
            $table->string('status', 32)->comment('订单状态');
            $table->text('pay_url')->nullable()->comment('支付链接');
            $table->string('country', 8)->default('VN')->comment('国家');
            $table->string('product_code', 64)->default('')->comment('支付产品编码快照');
            $table->string('wallet_type', 64)->default('')->comment('钱包类型');
            $table->string('bank_code', 64)->default('')->comment('银行或通道编码');
            $table->string('utr', 128)->default('')->comment('支付参考号');
            $table->unsignedInteger('query_attempts')->default(0)->comment('查单次数');
            $table->dateTime('next_query_at')->nullable()->comment('下次查单时间');
            $table->dateTime('last_queried_at')->nullable()->comment('最后查单时间');
            $table->dateTime('notified_at')->nullable()->comment('最后回调时间');
            $table->dateTime('credited_at')->nullable()->comment('点数入账时间');
            $table->string('last_error_code', 32)->default('')->comment('最后错误码');
            $table->string('last_error_message', 255)->default('')->comment('最后错误');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            $table->unique('merchant_order', 'uniq_payment_merchant_order');
            $table->unique(['provider', 'provider_order_number'], 'uniq_payment_provider_order');
            $table->unique(['user_id', 'idempotency_key'], 'uniq_payment_user_idempotency');
            $table->index(['status', 'next_query_at'], 'idx_payment_reconcile');
            $table->index(['user_id', 'created_at'], 'idx_payment_user_created');
        });
    }
    if (!$schema->hasColumn('ga_payment_orders', 'bank_account')) {
        $schema->table('ga_payment_orders', function ($table) {
            $table->longText('bank_account')->nullable()->after('customer_mobile')->comment('付款账号快照；仅新订单必填');
        });
    }
    if (!$schema->hasColumn('ga_payment_orders', 'product_code')) {
        $schema->table('ga_payment_orders', function ($table) {
            $table->string('product_code', 64)->default('')->after('country')->comment('支付产品编码快照');
        });
    }
    if (indexColumns('ga_payment_orders', 'uniq_payment_provider_order') !== ['provider', 'provider_order_number']) {
        if ($schema->hasIndex('ga_payment_orders', 'uniq_payment_provider_order')) {
            $schema->table('ga_payment_orders', function ($table) {
                $table->dropUnique('uniq_payment_provider_order');
            });
        }
        $schema->table('ga_payment_orders', function ($table) {
            $table->unique(['provider', 'provider_order_number'], 'uniq_payment_provider_order');
        });
    }
    ensureTableEngine('ga_payment_orders', 'InnoDB');

    if (!$schema->hasTable('ga_admin_operation_logs')) {
        $schema->create('ga_admin_operation_logs', function ($table) {
            $table->bigIncrements('id')->comment('日志ID');
            $table->unsignedInteger('admin_id')->nullable()->comment('管理员ID');
            $table->string('action', 128)->comment('动作');
            $table->string('target_type', 64)->default('')->comment('对象类型');
            $table->string('target_id', 64)->default('')->comment('对象ID');
            $table->json('payload')->nullable()->comment('操作内容');
            $table->dateTime('created_at')->useCurrent()->comment('创建时间');
            $table->index('admin_id', 'idx_admin_id');
            $table->index('action', 'idx_action');
        });
    }

    $legacyRonnyPayEnabled = in_array(strtolower(trim(app_env('RONNYPAY_ORDER_ENABLED', '0'))), ['1', 'true', 'yes', 'on'], true);
    $settings = [
        'third_party_enabled' => ['0', '第三方接口是否启用：0否，1是'],
        'third_party_base_url' => ['', '第三方接口地址'],
        'third_party_sign_secret' => ['', '第三方签名密钥'],
        'third_party_ws_url' => ['', '旧版第三方WebSocket地址，已停用'],
        'third_party_ws_urls' => ['', '旧版第三方WebSocket连接池地址列表，已停用'],
        'third_party_ws_connection_capacity' => ['10', '旧版单连接账号容量，已停用'],
        'third_party_script_token' => [bin2hex(random_bytes(24)), '第三方脚本池连接Token'],
        'third_party_script_ws_url' => [app_env('THIRD_PARTY_SCRIPT_WS_URL', ''), '我方第三方脚本WebSocket地址'],
        'third_party_transport' => ['websocket', '第三方通信方式：websocket或http'],
        'game_account_credential_key' => [app_env('GAME_ACCOUNT_CREDENTIAL_KEY', ''), '游戏账号密码加密密钥'],
        'game_account_max_count' => ['3', '单个用户同时可存在的游戏账号数量上限'],
        'facebook_login_enabled' => ['1', '是否允许新增Facebook登录游戏账号：1是，0否'],
        'google_login_enabled' => ['1', '是否允许新增Google登录游戏账号：1是，0否'],
        'registration_reward_points' => ['1', '新用户注册赠送配额点数，0表示关闭'],
        'invite_reward_min_role_level' => ['30', '邀请奖励最低角色等级，允许1至9999'],
        'payment_active_provider' => [$legacyRonnyPayEnabled ? 'ronnypay' : 'disabled', '活动支付方式：disabled、ronnypay或mkpay'],
        'payment_recharge_amount_vnd' => ['149000', '充值套餐金额，VND整数，只影响新订单'],
        'payment_callback_allowed_ips' => ['', '支付回调来源IP白名单，多个IP可用换行、逗号、分号、空格或竖线分隔，留空不校验'],
        'game_config_visibility_overrides' => ['{}', '游戏配置项用户端可见性相对默认值的覆盖JSON'],
        'auth_verification_mode' => ['security_question', '认证方式：security_question密保问题，email_code邮箱验证码'],
        'smtp_enabled' => ['0', 'SMTP是否启用：0否，1是'],
        'smtp_host' => ['', 'SMTP服务器地址'],
        'smtp_port' => ['587', 'SMTP端口'],
        'smtp_username' => ['', 'SMTP账号'],
        'smtp_password' => ['', 'SMTP密码或授权码'],
        'smtp_encryption' => ['tls', 'SMTP加密方式：tls、ssl、none'],
        'smtp_from_email' => ['', '发件邮箱'],
        'smtp_from_name' => ['Hoa Quán', '发件名称'],
        'game_log_queue_shards' => ['64', '日志队列分片数量，当前代码固定为64'],
        'game_log_writer_count' => [app_env('GAME_LOG_WRITER_COUNT', '8'), '日志写入进程数量，建议1万账号使用8'],
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
    echo 'ga_game_accounts自动重连字段：已同步' . PHP_EOL;
    echo 'ga_game_account_logs：废弃表已移除或不存在' . PHP_EOL;
    echo 'ga_game_account_log_segments：已同步' . PHP_EOL;
    echo 'ga_game_account_event_segments：已同步' . PHP_EOL;
    echo 'ga_game_account_log_states：已同步' . PHP_EOL;
    echo 'ga_game_account_task_states：已同步' . PHP_EOL;
    echo 'ga_announcements：已同步' . PHP_EOL;
    echo 'ga_user_point_transactions：已同步，表引擎已校正为InnoDB' . PHP_EOL;
    echo 'ga_payment_orders：已同步，表引擎已校正为InnoDB' . PHP_EOL;
    echo 'ga_admin_operation_logs：已同步' . PHP_EOL;
    echo 'SMTP配置项：已同步' . PHP_EOL;
    echo '认证方式配置项：已同步' . PHP_EOL;
    echo '邀请奖励配置项：已同步' . PHP_EOL;
    echo '第三方脚本连接配置项：已同步' . PHP_EOL;
    echo '高吞吐日志配置项：已同步' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '业务数据库结构同步失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
