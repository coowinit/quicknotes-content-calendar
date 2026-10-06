<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QNCC_Ajax_Controller {

    protected $query_service;
    protected $calendar_renderer;

    public function __construct( QNCC_Query_Service $query_service, QNCC_Calendar_Renderer $calendar_renderer ) {
        $this->query_service     = $query_service;
        $this->calendar_renderer = $calendar_renderer;

        add_action( 'wp_ajax_qn_calendar_get_quicknotes', array( $this, 'handle_day_list' ) );
        add_action( 'wp_ajax_nopriv_qn_calendar_get_quicknotes', array( $this, 'handle_day_list' ) );
        add_action( 'wp_ajax_qn_calendar_render_months', array( $this, 'handle_months_window' ) );
        add_action( 'wp_ajax_nopriv_qn_calendar_render_months', array( $this, 'handle_months_window' ) );
    }

    public function handle_day_list() {
        check_ajax_referer( 'qn_calendar_nonce', 'nonce' );

        $day       = isset( $_POST['day'] ) ? absint( $_POST['day'] ) : 0;
        $month     = isset( $_POST['month'] ) ? absint( $_POST['month'] ) : 0;
        $year      = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : 0;
        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'quicknotes';

        if ( ! checkdate( $month, $day, $year ) || ! $this->is_public_post_type( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( '日期或内容类型参数错误。', 'quicknotes-content-calendar' ) ), 400 );
        }

        $options         = QNCC_Settings_Page::get_options();
        $include_private = ! empty( $options['include_private'] );
        $statuses        = $this->query_service->get_allowed_statuses( $post_type, $include_private );

        list( $taxonomy, $term_ids ) = $this->get_filter_request( $post_type );

        $args = array(
            'post_type'              => $post_type,
            'posts_per_page'         => -1,
            'post_status'            => $statuses,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'date_query'             => array(
                array(
                    'year'  => $year,
                    'month' => $month,
                    'day'   => $day,
                ),
            ),
        );

        if ( $taxonomy && $term_ids ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy'         => $taxonomy,
                    'field'            => 'term_id',
                    'terms'            => $term_ids,
                    'include_children' => true,
                ),
            );
        }

        $query = new WP_Query( $args );

        if ( ! $query->have_posts() ) {
            wp_send_json_success(
                array(
                    'html'    => '',
                    'message' => __( '当日没有记录', 'quicknotes-content-calendar' ),
                )
            );
        }

        ob_start();
        echo '<ul class="tooltip-post-list">';
        while ( $query->have_posts() ) {
            $query->the_post();
            echo '<li class="tooltip-post-item"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
        }
        echo '</ul>';
        wp_reset_postdata();

        wp_send_json_success( array( 'html' => ob_get_clean() ) );
    }

    public function handle_months_window() {
        check_ajax_referer( 'qn_calendar_nonce', 'nonce' );

        $start_year  = isset( $_POST['start_year'] ) ? absint( $_POST['start_year'] ) : 0;
        $start_month = isset( $_POST['start_month'] ) ? absint( $_POST['start_month'] ) : 0;
        $months      = isset( $_POST['months'] ) ? absint( $_POST['months'] ) : 1;
        $post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'quicknotes';

        if ( ! checkdate( $start_month, 1, $start_year ) || ! $this->is_public_post_type( $post_type ) ) {
            wp_send_json_error( array( 'message' => __( '月份或内容类型参数错误。', 'quicknotes-content-calendar' ) ), 400 );
        }

        $months = min( 24, max( 1, $months ) );

        $options         = QNCC_Settings_Page::get_options();
        $include_private = ! empty( $options['include_private'] );
        list( $taxonomy, $term_ids ) = $this->get_filter_request( $post_type );

        $current_year  = (int) current_time( 'Y' );
        $current_month = (int) current_time( 'n' );

        // 前端不允许进入未来月份；后端也再次兜底。
        if ( $start_year > $current_year || ( $start_year === $current_year && $start_month > $current_month ) ) {
            $start_year  = $current_year;
            $start_month = $current_month;
        }

        $html = $this->calendar_renderer->render_months_window(
            $start_month,
            $start_year,
            $months,
            $post_type,
            $include_private,
            $taxonomy,
            $term_ids
        );

        wp_send_json_success(
            array(
                'html'          => $html,
                'label'         => $this->calendar_renderer->build_range_label( $start_year, $start_month, $months ),
                'start_year'    => $start_year,
                'start_month'   => $start_month,
                'current_year'  => $current_year,
                'current_month' => $current_month,
            )
        );
    }

    protected function is_public_post_type( $post_type ) {
        $object = get_post_type_object( $post_type );
        return $object && $object->public;
    }

    protected function get_filter_request( $post_type ) {
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            return array( '', array() );
        }

        $raw = isset( $_POST['term_ids'] ) ? wp_unslash( $_POST['term_ids'] ) : array();
        if ( ! is_array( $raw ) ) {
            $raw = explode( ',', (string) $raw );
        }

        $term_ids = array();
        foreach ( $raw as $term_id ) {
            $term_id = absint( $term_id );
            if ( $term_id > 0 && term_exists( $term_id, $taxonomy ) ) {
                $term_ids[] = $term_id;
            }
        }

        return array( $taxonomy, array_values( array_unique( $term_ids ) ) );
    }
}
