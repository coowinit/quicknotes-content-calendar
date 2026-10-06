<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once QNCC_PLUGIN_DIR . 'includes/class-qncc-query-service.php';
require_once QNCC_PLUGIN_DIR . 'includes/class-qncc-calendar-renderer.php';
require_once QNCC_PLUGIN_DIR . 'includes/class-qncc-ajax-controller.php';
require_once QNCC_PLUGIN_DIR . 'includes/class-qncc-settings-page.php';

class QNCC_Plugin {

    protected $query_service;
    protected $calendar_renderer;
    protected $ajax_controller;
    protected $settings_page;

    public function __construct() {
        $this->query_service     = new QNCC_Query_Service();
        $this->calendar_renderer = new QNCC_Calendar_Renderer( $this->query_service );
        $this->ajax_controller   = new QNCC_Ajax_Controller( $this->query_service, $this->calendar_renderer );
        $this->settings_page     = new QNCC_Settings_Page();

        add_shortcode( 'quicknotes_calendar', array( $this->calendar_renderer, 'render_calendar_shortcode' ) );

        // 前端资源。保持全局 enqueue，避免短码在 wp_head 之后渲染时出现样式未输出的问题。
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 内容、状态、日期或分类关系发生变化时提升缓存版本。
        add_action( 'save_post', array( $this->query_service, 'bump_cache_version_by_post' ) );
        add_action( 'trashed_post', array( $this->query_service, 'bump_cache_version_by_post' ) );
        add_action( 'untrashed_post', array( $this->query_service, 'bump_cache_version_by_post' ) );
        add_action( 'before_delete_post', array( $this->query_service, 'bump_cache_version_by_post' ) );
        add_action( 'set_object_terms', array( $this->query_service, 'bump_cache_version_by_terms' ), 10, 1 );
    }

    public function enqueue_assets() {
        $handle = 'qn-content-calendar';

        wp_enqueue_style(
            $handle,
            QNCC_PLUGIN_URL . 'assets/css/quicknotes-calendar.css',
            array(),
            QNCC_VERSION
        );

        wp_enqueue_script(
            $handle,
            QNCC_PLUGIN_URL . 'assets/js/quicknotes-calendar.js',
            array( 'jquery' ),
            QNCC_VERSION,
            true
        );

        wp_localize_script(
            $handle,
            'QNCalendarAjax',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'qn_calendar_nonce' ),
                'i18n'     => array(
                    'loading' => __( '加载中...', 'quicknotes-content-calendar' ),
                    'failed'  => __( '加载失败', 'quicknotes-content-calendar' ),
                    'error'   => __( '请求出错', 'quicknotes-content-calendar' ),
                    'empty'   => __( '当日没有记录', 'quicknotes-content-calendar' ),
                    'items'   => __( '条内容', 'quicknotes-content-calendar' ),
                ),
            )
        );
    }
}
