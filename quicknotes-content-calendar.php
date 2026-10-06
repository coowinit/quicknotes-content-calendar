<?php
/**
 * Plugin Name:       Quicknotes 内容日历 · 按月可视化笔记与文章
 * Description:       为指定文章类型按月生成内容日历，以小圆点标记有内容的日期，桌面端鼠标悬浮显示当天文章列表，移动端点击后在日历下方展开详情，支持私密文章、分类 / 标签过滤和 Ajax 月份导航。
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Winson Yang
 * Text Domain:       quicknotes-content-calendar
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QNCC_VERSION', '1.0.0' );
define( 'QNCC_PLUGIN_FILE', __FILE__ );
define( 'QNCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QNCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once QNCC_PLUGIN_DIR . 'includes/class-qncc-plugin.php';

add_action(
    'plugins_loaded',
    static function () {
        new QNCC_Plugin();
    }
);
