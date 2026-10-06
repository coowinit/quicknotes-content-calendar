<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QNCC_Calendar_Renderer {

    /** @var QNCC_Query_Service */
    protected $query_service;

    public function __construct( QNCC_Query_Service $query_service ) {
        $this->query_service = $query_service;
    }

    /**
     * 短码回调
     * [quicknotes_calendar months="7" post_type="quicknotes"]
     */
    public function render_calendar_shortcode( $atts ) {
        // 从设置中读取默认值
        if ( class_exists( 'QNCC_Settings_Page' ) ) {
            $options         = QNCC_Settings_Page::get_options();
            $default_months  = isset( $options['months'] ) ? (int) $options['months'] : 7;
            $default_pt      = ! empty( $options['post_type'] ) ? $options['post_type'] : 'quicknotes';
            $include_private = ! empty( $options['include_private'] );
            $taxonomy        = ! empty( $options['taxonomy'] ) ? $options['taxonomy'] : '';
            $term_ids        = isset( $options['terms'] ) && is_array( $options['terms'] )
                ? array_map( 'intval', $options['terms'] )
                : array();
        } else {
            $default_months  = 7;
            $default_pt      = 'quicknotes';
            $include_private = true;
            $taxonomy        = '';
            $term_ids        = array();
        }

        $atts = shortcode_atts(
            array(
                'months'    => $default_months,
                'post_type' => $default_pt,
            ),
            $atts,
            'quicknotes_calendar'
        );

        $months    = min( 24, max( 1, (int) $atts['months'] ) );
        $post_type = sanitize_key( $atts['post_type'] );
        $pt_object = get_post_type_object( $post_type );
        if ( ! $pt_object || ! $pt_object->public ) {
            $post_type = $default_pt;
        }

        $current_month = (int) current_time( 'n' );
        $current_year  = (int) current_time( 'Y' );

        // 当前窗口的“起始月份”（最新的那个月份）
        $start_month = $current_month;
        $start_year  = $current_year;

        // 标签：例如 “2025年5月 – 2025年11月”
        $label = $this->build_range_label( $start_year, $start_month, $months );

        // data-term-ids 使用逗号分隔
        $term_ids_attr = '';
        if ( ! empty( $term_ids ) ) {
            $term_ids_attr = implode( ',', array_map( 'intval', $term_ids ) );
        }

        ob_start();
        ?>
        <div class="qn-content-calendar"
             data-post-type="<?php echo esc_attr( $post_type ); ?>"
             data-months="<?php echo esc_attr( $months ); ?>"
             data-start-year="<?php echo esc_attr( $start_year ); ?>"
             data-start-month="<?php echo esc_attr( $start_month ); ?>"
             data-current-year="<?php echo esc_attr( $current_year ); ?>"
             data-current-month="<?php echo esc_attr( $current_month ); ?>"
             data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
             data-term-ids="<?php echo esc_attr( $term_ids_attr ); ?>">

            <div class="qncc-toolbar">
                <button type="button" class="qncc-nav-btn qncc-prev-month" aria-label="<?php esc_attr_e( '上一月', 'quicknotes-content-calendar' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"><path d="M9.78 12.78a.75.75 0 0 1-1.06 0L4.47 8.53a.75.75 0 0 1 0-1.06l4.25-4.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042L6.06 8l3.72 3.72a.75.75 0 0 1 0 1.06Z"></path></svg>
                </button>

                <div class="qncc-current-label">
                    <?php echo esc_html( $label ); ?>
                </div>

                <button type="button" class="qncc-nav-btn qncc-next-month" aria-label="<?php esc_attr_e( '下一月', 'quicknotes-content-calendar' ); ?>"
                    <?php
                    // 初始就停在当前月，不能再往“未来”走
                    if ( $start_year === $current_year && $start_month === $current_month ) {
                        echo 'disabled="disabled"';
                    }
                    ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"><path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"></path></svg>
                </button>
            </div>

            <div class="qncc-calendars">
                <?php
                // 初次渲染月份窗口
                echo $this->render_months_window(
                    $start_month,
                    $start_year,
                    $months,
                    $post_type,
                    $include_private,
                    $taxonomy,
                    $term_ids
                );
                ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * 渲染从某个“起始月份”往前推 N 个月的窗口 HTML
     *
     * @param int      $start_month 起始月份（最新）
     * @param int      $start_year  起始年份
     * @param int      $months      窗口包含的月份数
     * @param string   $post_type   内容类型
     * @param bool     $include_private 是否包含私密
     * @param string   $taxonomy        可选 taxonomy
     * @param int[]    $term_ids        可选 term_ids
     *
     * @return string
     */
    public function render_months_window( $start_month, $start_year, $months, $post_type, $include_private = true, $taxonomy = '', $term_ids = array() ) {
        $months          = min( 24, max( 1, (int) $months ) );
        $start_month     = (int) $start_month;
        $start_year      = (int) $start_year;
        $post_type       = sanitize_key( $post_type );
        $include_private = (bool) $include_private;
        $taxonomy        = $taxonomy ? sanitize_key( $taxonomy ) : '';
        $term_ids        = is_array( $term_ids ) ? array_map( 'intval', $term_ids ) : array();

        $html  = '';
        $month = $start_month;
        $year  = $start_year;

        for ( $i = 0; $i < $months; $i++ ) {
            if ( $month < 1 ) {
                $month += 12;
                $year--;
            } elseif ( $month > 12 ) {
                $month -= 12;
                $year++;
            }

            $html .= $this->generate_calendar(
                $month,
                $year,
                $post_type,
                $include_private,
                $taxonomy,
                $term_ids
            );

            // 往前推一个月
            $month--;
        }

        return $html;
    }

    /**
     * 根据起始月份 + 月份数，生成“日期范围”标签
     * 例如：2025年5月 – 2025年11月 / 2025年11月
     */
    public function build_range_label( $start_year, $start_month, $months ) {
        $start_year  = (int) $start_year;
        $start_month = (int) $start_month;
        $months      = max( 1, (int) $months );

        $latest_ts = mktime( 0, 0, 0, $start_month, 1, $start_year );

        if ( $months === 1 ) {
            // 只显示一个月
            return date_i18n( 'Y年n月', $latest_ts );
        }

        // 最早月份 = 起始月份 - (months - 1)
        $earliest_month = $start_month - ( $months - 1 );
        $earliest_year  = $start_year;

        while ( $earliest_month < 1 ) {
            $earliest_month += 12;
            $earliest_year--;
        }

        $earliest_ts = mktime( 0, 0, 0, $earliest_month, 1, $earliest_year );

        $earliest_str = date_i18n( 'Y年n月', $earliest_ts );
        $latest_str   = date_i18n( 'Y年n月', $latest_ts );

        return sprintf( '%s – %s', $earliest_str, $latest_str );
    }

    /**
     * 生成某个月份的日历 HTML
     */
    protected function generate_calendar( $month, $year, $post_type, $include_private = true, $taxonomy = '', $term_ids = array() ) {
        $month          = (int) $month;
        $year           = (int) $year;
        $include_private = (bool) $include_private;
        $taxonomy       = $taxonomy ? sanitize_key( $taxonomy ) : '';
        $term_ids       = is_array( $term_ids ) ? array_map( 'intval', $term_ids ) : array();

        if ( $month < 1 ) {
            $month += 12;
            $year--;
        } elseif ( $month > 12 ) {
            $month -= 12;
            $year++;
        }

        $days_in_month = (int) cal_days_in_month( CAL_GREGORIAN, $month, $year );
        $posts_by_day  = $this->query_service->get_month_counts(
            $year,
            $month,
            $post_type,
            $include_private,
            $taxonomy,
            $term_ids
        );

        $today           = (int) current_time( 'j' );
        $cur_month       = (int) current_time( 'n' );
        $cur_year        = (int) current_time( 'Y' );
        $first_day_weekday = (int) date( 'w', mktime( 0, 0, 0, $month, 1, $year ) ); // 0=周日

        $out  = '<table class="custom-calendar">';
        $out .= '<caption>' . esc_html( date_i18n( 'Y年n月', mktime( 0, 0, 0, $month, 1, $year ) ) ) . '</caption>';
        $out .= '
            <thead>
                <tr>
                    <th scope="col">日</th>
                    <th scope="col">一</th>
                    <th scope="col">二</th>
                    <th scope="col">三</th>
                    <th scope="col">四</th>
                    <th scope="col">五</th>
                    <th scope="col">六</th>
                </tr>
            </thead>
            <tbody>';

        $out .= '<tr>';

        // 月初前的空格
        for ( $i = 0; $i < $first_day_weekday; $i++ ) {
            $out .= '<td></td>';
        }

        $day = 1;

        while ( $day <= $days_in_month ) {
            $num_posts       = isset( $posts_by_day[ $day ] ) ? (int) $posts_by_day[ $day ] : 0;
            $has_posts_class = $num_posts > 0 ? ' has-post' : '';

            $weekday_index = ( $first_day_weekday + $day - 1 ) % 7;
            $align_class   = '';
            if ( 0 === $weekday_index ) {
                $align_class = ' tooltip-align-left';
            } elseif ( 6 === $weekday_index ) {
                $align_class = ' tooltip-align-right';
            }

            $out .= '<td><div class="event-day-box' . esc_attr( $has_posts_class . $align_class ) . '">';

            $classes = 'calendar-day';
            if ( $day === $today && $month === $cur_month && $year === $cur_year ) {
                $classes .= ' cur-day';
            }

            $tooltip_id = sprintf( 'qncc-tooltip-%d-%02d-%02d', $year, $month, $day );

            // 有内容的日期使用 button，便于键盘和触屏操作；普通日期保持纯文本。
            if ( $num_posts > 0 ) {
                $out .= sprintf(
                    '<button type="button" class="%1$s" data-day="%2$d" data-month="%3$d" data-year="%4$d" aria-expanded="false" aria-controls="%5$s" aria-label="%6$s">%2$d</button>',
                    esc_attr( $classes ),
                    $day,
                    $month,
                    $year,
                    esc_attr( $tooltip_id ),
                    esc_attr( sprintf( __( '%1$d年%2$d月%3$d日，共 %4$d 条内容', 'quicknotes-content-calendar' ), $year, $month, $day, $num_posts ) )
                );
            } else {
                $out .= sprintf(
                    '<span class="%1$s" data-day="%2$d" data-month="%3$d" data-year="%4$d">%2$d</span>',
                    esc_attr( $classes ),
                    $day,
                    $month,
                    $year
                );
            }

            // 有文章：底部标记 + tooltip 容器
            if ( $num_posts > 0 ) {
                if ( $num_posts > 3 ) {
                    // 多于 3 篇：统一显示 3+
                    $display_text = '3+';
                    $extra_class  = ' post-count-more';
                    $data_count   = 3;
                } else {
                    // 1~3 篇：• / •• / •••
                    $display_text = str_repeat( '•', $num_posts );
                    $extra_class  = '';
                    $data_count   = $num_posts;
                }

                $out .= sprintf(
                    '<span class="post-count%1$s" aria-hidden="true" data-count="%2$d" data-total="%3$d">%4$s</span>',
                    esc_attr( $extra_class ),
                    (int) $data_count,
                    (int) $num_posts,
                    esc_html( $display_text )
                );

                $out .= '<div id="' . esc_attr( $tooltip_id ) . '" class="calendar-tooltip" role="dialog" aria-live="polite"></div>';
            }

            $out .= '</div></td>';

            // 周六换行（最后一天除外）
            if ( ( $first_day_weekday + $day - 1 ) % 7 === 6 && $day !== $days_in_month ) {
                $out .= '</tr><tr>';
            }

            $day++;
        }

        $out .= '</tr></tbody></table>';

        return $out;
    }
}