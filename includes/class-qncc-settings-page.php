<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QNCC_Settings_Page {

    const OPTION_KEY = 'qncc_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * 后台「设置」菜单下添加子菜单
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Quicknotes 内容日历设置', 'quicknotes-content-calendar' ),
            __( '内容日历', 'quicknotes-content-calendar' ),
            'manage_options',
            'qncc-settings',
            array( $this, 'render_page' )
        );
    }

    /**
     * 注册设置、分区和字段
     */
    public function register_settings() {
        register_setting(
            'qncc_options_group',
            self::OPTION_KEY,
            array( $this, 'sanitize_options' )
        );

        add_settings_section(
            'qncc_main_section',
            __( '内容日历基础设置', 'quicknotes-content-calendar' ),
            function () {
                echo '<p>' . esc_html__( '配置内容日历的默认数据源和显示范围。短码未显式指定参数时会使用这里的设置。', 'quicknotes-content-calendar' ) . '</p>';
            },
            'qncc-settings'
        );

        // 默认 post_type
        add_settings_field(
            'qncc_post_type',
            __( '内容来源文章类型', 'quicknotes-content-calendar' ),
            array( $this, 'render_post_type_field' ),
            'qncc-settings',
            'qncc_main_section'
        );

        // 默认月份数
        add_settings_field(
            'qncc_months',
            __( '默认显示月份数', 'quicknotes-content-calendar' ),
            array( $this, 'render_months_field' ),
            'qncc-settings',
            'qncc_main_section'
        );

        // 是否包含私密文章
        add_settings_field(
            'qncc_include_private',
            __( '包含私密文章（private）', 'quicknotes-content-calendar' ),
            array( $this, 'render_include_private_field' ),
            'qncc-settings',
            'qncc_main_section'
        );

        // 分类法过滤：taxonomy 选择
        add_settings_field(
            'qncc_taxonomy',
            __( '按分类 / 标签过滤', 'quicknotes-content-calendar' ),
            array( $this, 'render_taxonomy_field' ),
            'qncc-settings',
            'qncc_main_section'
        );

        // 分类法过滤：具体 terms 选择
        add_settings_field(
            'qncc_terms',
            __( '过滤的分类 / 标签项', 'quicknotes-content-calendar' ),
            array( $this, 'render_terms_field' ),
            'qncc-settings',
            'qncc_main_section'
        );
    }

    /**
     * 选项清洗
     */
    public function sanitize_options( $input ) {
        $output = array();

        // 允许的 public post types
        $public_post_types = get_post_types( array( 'public' => true ), 'names' );

        // months
        $months = isset( $input['months'] ) ? (int) $input['months'] : 7;
        if ( $months < 1 ) {
            $months = 1;
        } elseif ( $months > 24 ) {
            $months = 24; // 最多两年
        }
        $output['months'] = $months;

        // post_type
        $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'quicknotes';
        if ( ! in_array( $post_type, $public_post_types, true ) ) {
            // 如果 quicknotes 不存在，就退回到 post
            $post_type = in_array( 'quicknotes', $public_post_types, true ) ? 'quicknotes' : 'post';
        }
        $output['post_type'] = $post_type;

        // include_private（勾选则为 1，否则 0）
        $output['include_private'] = ! empty( $input['include_private'] ) ? 1 : 0;

        // taxonomy 过滤
        $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
        $terms    = array();

        if ( $taxonomy && taxonomy_exists( $taxonomy ) && is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            if ( ! empty( $input['terms'] ) && is_array( $input['terms'] ) ) {
                foreach ( $input['terms'] as $term_id ) {
                    $term_id = (int) $term_id;
                    if ( $term_id > 0 ) {
                        $term_obj = get_term( $term_id, $taxonomy );
                        if ( $term_obj && ! is_wp_error( $term_obj ) ) {
                            $terms[] = $term_id;
                        }
                    }
                }
            }
            $output['taxonomy'] = $taxonomy;
            $output['terms']    = array_values( array_unique( $terms ) );
        } else {
            // 未选择或无效时，不做 taxonomy 过滤
            $output['taxonomy'] = '';
            $output['terms']    = array();
        }

        return $output;
    }

    /**
     * 获取合并默认值后的 options
     */
    public static function get_options() {
        $defaults = array(
            'post_type'       => 'quicknotes',
            'months'          => 7,
            'include_private' => 1,          // 默认包含私密文章
            'taxonomy'        => '',         // 默认不过滤
            'terms'           => array(),
        );
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }

        // 确保 terms 一定是数组
        if ( ! isset( $opts['terms'] ) || ! is_array( $opts['terms'] ) ) {
            $opts['terms'] = array();
        }

        return array_merge( $defaults, $opts );
    }

    /**
     * 字段：post_type 下拉选择
     */
    public function render_post_type_field() {
        $options    = self::get_options();
        $current    = isset( $options['post_type'] ) ? $options['post_type'] : 'quicknotes';
        $post_types = get_post_types( array( 'public' => true ), 'objects' );

        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[post_type]">';
        foreach ( $post_types as $pt ) {
            $selected = selected( $current, $pt->name, false );
            printf(
                '<option value="%1$s" %3$s>%2$s (%1$s)</option>',
                esc_attr( $pt->name ),
                esc_html( $pt->labels->singular_name ),
                $selected
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( '选择作为内容日历数据源的文章类型，例如 quicknotes、post 等。', 'quicknotes-content-calendar' ) . '</p>';
    }

    /**
     * 字段：月份数
     */
    public function render_months_field() {
        $options = self::get_options();
        $months  = isset( $options['months'] ) ? (int) $options['months'] : 7;

        printf(
            '<input type="number" min="1" max="24" name="%1$s[months]" value="%2$d" />',
            esc_attr( self::OPTION_KEY ),
            $months
        );
        echo '<p class="description">' . esc_html__( '默认显示最近几个月（包含当前月），范围 1–24。', 'quicknotes-content-calendar' ) . '</p>';
    }

    /**
     * 字段：是否包含私密文章
     */
    public function render_include_private_field() {
        $options         = self::get_options();
        $include_private = ! empty( $options['include_private'] );

        printf(
            '<label><input type="checkbox" name="%1$s[include_private]" value="1" %2$s /> %3$s</label>',
            esc_attr( self::OPTION_KEY ),
            checked( $include_private, true, false ),
            esc_html__( '勾选后，仅对具备对应私密内容读取权限的当前用户统计并显示 private 内容；未登录访客不会看到私密内容。', 'quicknotes-content-calendar' )
        );

        echo '<p class="description">' . esc_html__( '关闭时仅统计已发布文章（publish）。', 'quicknotes-content-calendar' ) . '</p>';
    }

    /**
     * 字段：选择要过滤的 taxonomy
     */
    public function render_taxonomy_field() {
        $options   = self::get_options();
        $post_type = isset( $options['post_type'] ) ? $options['post_type'] : 'quicknotes';
        $current   = ! empty( $options['taxonomy'] ) ? $options['taxonomy'] : '';

        $tax_objects = get_object_taxonomies( $post_type, 'objects' );

        echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[taxonomy]">';
        echo '<option value="">' . esc_html__( '不过滤（全部分类 / 标签）', 'quicknotes-content-calendar' ) . '</option>';

        foreach ( $tax_objects as $tax ) {
            // 只列出 public 的 taxonomy，避免技术性内部 taxonomy
            if ( ! $tax->public ) {
                continue;
            }
            printf(
                '<option value="%1$s" %3$s>%2$s (%1$s)</option>',
                esc_attr( $tax->name ),
                esc_html( $tax->labels->singular_name ),
                selected( $current, $tax->name, false )
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__( '可选：只统计属于某个分类法（如分类 category、标签 post_tag、或自定义 taxonomy）的内容。', 'quicknotes-content-calendar' ) . '</p>';
        echo '<p class="description">' . esc_html__( '如果刚修改了文章类型，请先保存本页，然后重新打开以刷新可用的分类法列表。', 'quicknotes-content-calendar' ) . '</p>';
    }

    /**
     * 字段：选择具体 terms
     */
    public function render_terms_field() {
        $options   = self::get_options();
        $post_type = isset( $options['post_type'] ) ? $options['post_type'] : 'quicknotes';
        $taxonomy  = ! empty( $options['taxonomy'] ) ? $options['taxonomy'] : '';

        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            echo '<p class="description">' . esc_html__( '当前未启用分类 / 标签过滤，或选择的分类法不适用于当前文章类型。', 'quicknotes-content-calendar' ) . '</p>';
            return;
        }

        $selected_ids = isset( $options['terms'] ) && is_array( $options['terms'] )
            ? array_map( 'intval', $options['terms'] )
            : array();

        $terms = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            )
        );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            echo '<p class="description">' . esc_html__( '该分类法暂无可选项。', 'quicknotes-content-calendar' ) . '</p>';
            return;
        }

        echo '<div style="max-height: 220px; overflow: auto; border: 1px solid #ddd; padding: 8px; background: #fff;">';
        foreach ( $terms as $term ) {
            $checked = in_array( (int) $term->term_id, $selected_ids, true );
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[terms][]" value="%2$d" %3$s /> %4$s (%2$d)</label>',
                esc_attr( self::OPTION_KEY ),
                (int) $term->term_id,
                checked( $checked, true, false ),
                esc_html( $term->name )
            );
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( '如果勾选了具体分类 / 标签，日历只会统计属于这些项的文章；不勾选则视为不过滤。', 'quicknotes-content-calendar' ) . '</p>';
    }

    /**
     * 设置页面渲染
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Quicknotes 内容日历设置', 'quicknotes-content-calendar' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'qncc_options_group' );
                do_settings_sections( 'qncc-settings' );
                submit_button();
                ?>
            </form>

            <hr />
            <h2><?php esc_html_e( '使用示例', 'quicknotes-content-calendar' ); ?></h2>
            <p><?php esc_html_e( '在页面或文章中插入短码：', 'quicknotes-content-calendar' ); ?></p>
            <code>[quicknotes_calendar]</code>
            <p><?php esc_html_e( '如需临时覆盖设置中的文章类型或月份数：', 'quicknotes-content-calendar' ); ?></p>
            <code>[quicknotes_calendar post_type="post" months="3"]</code>
        </div>
        <?php
    }
}