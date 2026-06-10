<?php
/**
 * 网页验证系统 - 数据库配置模板
 * 一键安装：浏览器打开网站根目录 install.php 将自动生成本文件为 config.php
 * 手动安装：复制本文件为 config.php 并修改数据库账号
 */
return [
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'auth_system',
    'db_user'     => 'root',
    'db_password' => '',
    'db_charset'  => 'utf8mb4',
    'session_name' => 'AUTH_SESS',
    /** 用于 auth/reset_admin.php 重置管理员密码的密钥，请改为长随机字符串 */
    'reset_admin_key' => 'please_change_this_to_a_long_random_string',
];
