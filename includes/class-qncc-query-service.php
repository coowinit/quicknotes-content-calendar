<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QNCC_Query_Service {

    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * 获取某年某月每天的文章数量。
     *
     * 公开内容使用 transient 缓存；包含 private 时，为避免把与用户权限相关的统计写入共享缓存，直接实时查询。
     *
     * @param int    $year
     * @param int    $month
     * @param string $post_type
     * @param bool   $include_private
     * @param string $taxonomy
     * @param int[]  $term_ids
     * @return array<int,int> [day => count]
     */
    public function get_month_counts( $year, $month, $post_type, $include_private = true, $taxonomy = '', $term_ids = array() ) {
        $year      = (int) $year;
        $month     = (int) $month;
        $post_type = $this->normalize_post_type( $post_type );

        if ( ! $post_type || ! checkdate( $month, 1, $year ) ) {
            return array();
        }

        $taxonomy = $this->normalize_taxonomy( $taxonomy, $post_type );
        $term_ids = $this->normalize_term_ids( $term_ids, $taxonomy );

        $statuses          = $this->get_allowed_statuses( $post_type, $include_private );
        $can_cache_results = ! in_array( 'private', $statuses, true );
        $cache_key         = '';

        if ( $can_cache_results ) {
            $cache_key = $this->build_month_cache_key( $year, $month, $post_type, $taxonomy, $term_ids );
            $cached    = get_transient( $cache_key );

            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $args = array(
            'post_type'              => $post_type,
            'posts_per_page'         => -1,
            'post_status'            => $statuses,
            'year'                   => $year,
            'monthnum'               => $month,
            'orderby'                => 'date',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
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

        $query  = new WP_Query( $args );
        $counts = array();

        foreach ( $query->posts as $post_id ) {
            $day = (int) get_post_time( 'j', false, $post_id );
            if ( $day < 1 || $day > 31 ) {
                continue;
            }

            if ( ! isset( $counts[ $day ] ) ) {
                $counts[ $day ] = 0;
            }
            $counts[ $day ]++;
        }

        if ( $can_cache_results && $cache_key ) {
            set_transient( $cache_key, $counts, self::CACHE_TTL );
        }

        return $counts;
    }

    /**
     * 当前访问者实际允许查询的状态。
     */
    public function get_allowed_statuses( $post_type, $include_private ) {
        $statuses = array( 'publish' );

        if ( ! $include_private ) {
            return $statuses;
        }

        $post_type_object = get_post_type_object( $post_type );
        if ( ! $post_type_object || empty( $post_type_object->cap->read_private_posts ) ) {
            return $statuses;
        }

        if ( current_user_can( $post_type_object->cap->read_private_posts ) ) {
            $statuses[] = 'private';
        }

        return $statuses;
    }

    /**
     * 内容发生变化时提升对应 post type 的缓存版本。
     * 这样可同时让旧月份、新月份以及 taxonomy 过滤缓存自然失效，不需要枚举删除所有 transient。
     */
    public function bump_cache_version_by_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || empty( $post->post_type ) ) {
            return;
        }

        $post_type_object = get_post_type_object( $post->post_type );
        if ( ! $post_type_object || ! $post_type_object->public ) {
            return;
        }

        $this->bump_cache_version( $post->post_type );
    }

    /**
     * 设置分类 / 标签后也使缓存失效。
     */
    public function bump_cache_version_by_terms( $object_id ) {
        $this->bump_cache_version_by_post( $object_id );
    }

    protected function bump_cache_version( $post_type ) {
        $post_type = sanitize_key( $post_type );
        if ( ! $post_type ) {
            return;
        }

        $option_key = $this->get_cache_version_option_key( $post_type );
        $version    = (int) get_option( $option_key, 1 );
        update_option( $option_key, $version + 1, false );
    }

    protected function get_cache_version( $post_type ) {
        return max( 1, (int) get_option( $this->get_cache_version_option_key( $post_type ), 1 ) );
    }

    protected function get_cache_version_option_key( $post_type ) {
        return 'qncc_cache_v_' . sanitize_key( $post_type );
    }

    protected function normalize_post_type( $post_type ) {
        $post_type = sanitize_key( $post_type ? $post_type : 'quicknotes' );
        $object    = get_post_type_object( $post_type );

        if ( ! $object || ! $object->public ) {
            return '';
        }

        return $post_type;
    }

    protected function normalize_taxonomy( $taxonomy, $post_type ) {
        $taxonomy = sanitize_key( $taxonomy );

        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            return '';
        }

        return $taxonomy;
    }

    protected function normalize_term_ids( $term_ids, $taxonomy ) {
        if ( ! $taxonomy ) {
            return array();
        }

        if ( ! is_array( $term_ids ) ) {
            $term_ids = explode( ',', (string) $term_ids );
        }

        $valid_ids = array();
        foreach ( $term_ids as $term_id ) {
            $term_id = (int) $term_id;
            if ( $term_id > 0 && term_exists( $term_id, $taxonomy ) ) {
                $valid_ids[] = $term_id;
            }
        }

        $valid_ids = array_values( array_unique( $valid_ids ) );
        sort( $valid_ids, SORT_NUMERIC );

        return $valid_ids;
    }

    protected function build_month_cache_key( $year, $month, $post_type, $taxonomy, $term_ids ) {
        $filter_hash = 'all';
        if ( $taxonomy && $term_ids ) {
            $filter_hash = substr( md5( $taxonomy . ':' . implode( ',', $term_ids ) ), 0, 10 );
        }

        return sprintf(
            'qncc_%s_%d_%04d%02d_%s',
            $post_type,
            $this->get_cache_version( $post_type ),
            $year,
            $month,
            $filter_hash
        );
    }
}
