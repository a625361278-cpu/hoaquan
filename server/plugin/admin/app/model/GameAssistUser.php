<?php

namespace plugin\admin\app\model;

/**
 * GameAssist 产品用户，真实数据表为 ga_users。
 */
class GameAssistUser extends Base
{
    protected $table = 'ga_users';

    protected $primaryKey = 'id';

    protected $hidden = ['password_hash'];
}
