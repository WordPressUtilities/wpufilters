<?php

/*
Plugin Name: WPU Filters
Plugin URI: https://github.com/WordPressUtilities/wpufilters
Description: Simple filters for WordPress
Version: 0.6.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUFilters {
    private $plugin_version = '0.6.1';
    private $query_key = 'wpufilters_query';
    private $search_parameter = 'search';
    private $table_index = 'wpufilters_index';
    private $filled_filters = array();
    private $post_type = false;
    private $filters = array();
    private $filters_types = array(
        'postmeta',
        'tax'
    );

    public function __construct() {
        add_filter('plugins_loaded', array(&$this, 'plugins_loaded'), 50);
    }

    /**
     * plugins_loaded filters
     */
    public function plugins_loaded() {

        /* Translation */
        load_plugin_textdomain('wpufilters', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        /* Setup filters */
        $this->filters = $this->set_filters(apply_filters('wpufilters_filters', array()));
        $this->post_type = apply_filters('wpufilters__post_type', $this->post_type);
        $this->search_parameter = apply_filters('wpufilters_search_parameter', $this->search_parameter);

        /* Hooks */
        if (!is_admin()) {
            add_action('pre_get_posts', array(&$this, 'setup_post_query'));
            add_action('wp', array(&$this, 'setup_active_filters'));
        }

        /* Auto update */
        include dirname(__FILE__) . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpufilters\WPUBaseUpdate(
            'WordPressUtilities',
            'wpufilters',
            $this->plugin_version);

        /* Indexation */
        $this->setup_indexation();
    }

    /**
     * Ensure filters are at a correct format and filled
     * @param array $filters filters list
     */
    public function set_filters($filters = array()) {
        $_filters = array();
        foreach ($filters as $key => $filter) {
            if (!is_array($filter)) {
                $filter = array();
            }
            /* Set public name */
            if (!isset($filter['name'])) {
                $filter['name'] = $key;
            }
            if (!isset($filter['public_name'])) {
                $filter['public_name'] = $filter['name'];
            }
            /* Set type */
            if (!isset($filter['type']) || !in_array($filter['type'], $this->filters_types)) {
                $filter['type'] = $this->filters_types[0];
            }
            /* Set multiple values */
            if (!isset($filter['multiple_values'])) {
                $filter['multiple_values'] = true;
            }
            /* Set multiple values */
            if (!isset($filter['operator'])) {
                $filter['operator'] = 'IN';
            }
            if (!isset($filter['values']) || !is_array($filter['values'])) {
                $filter['values'] = array();
                if ($filter['type'] == 'postmeta') {
                    $filter['values'] = array(__('No', 'wpufilters'), __('Yes', 'wpufilters'));
                }
                if ($filter['type'] == 'tax') {
                    $terms = get_terms($key);
                    foreach ($terms as $term) {
                        $filter['values'][$term->slug] = $term->name;
                    }
                }
            }
            $_filters[$key] = $filter;
        }

        return $_filters;
    }

    /* ----------------------------------------------------------
      Query logic
    ---------------------------------------------------------- */

    private function get_active_filters() {
        $active_filters = array();
        if (!isset($_GET[$this->query_key])) {
            return $active_filters;
        }
        $query = json_decode(stripslashes($_GET[$this->query_key]));
        if (!is_object($query)) {
            return $active_filters;
        }
        $query = get_object_vars($query);
        foreach ($query as $filter_id => $filter_value) {
            if (!array_key_exists($filter_id, $this->filters)) {
                continue;
            }
            if (is_object($filter_value)) {
                $filter_value = get_object_vars($filter_value);
            }
            if (!is_array($filter_value)) {
                continue;
            }
            $current_filter = $this->filters[$filter_id];
            $active_values = array();
            foreach ($filter_value as $value) {
                if (array_key_exists($value, $current_filter['values'])) {
                    $active_values[] = $value;
                }
            }
            if (!empty($active_values)) {
                $active_filters[$filter_id] = $active_values;
            }
        }
        return $active_filters;
    }

    /**
     * Update the query for a filter value
     * @param  string $filter_id    Selected filter
     * @param  string $filter_value Desired value
     * @return array                Updated query
     */
    private function update_filter_query($filter_id = '', $value_id = '') {
        $_active_filters = $this->get_active_filters();
        if (!array_key_exists($filter_id, $_active_filters) || !$this->can_get_multiple_values($filter_id)) {
            $_active_filters[$filter_id] = array();
        }
        /* Add to query if not present */
        if (!$this->is_filter_active($filter_id, $value_id)) {
            $_active_filters[$filter_id][] = $value_id;
        }
        /* Remove from query if present */
        else {
            $value_position = array_search($value_id, $_active_filters[$filter_id]);
            unset($_active_filters[$filter_id][$value_position]);
        }
        /* Clean empty filters */
        $active_filters = array();
        foreach ($_active_filters as $id => $filter_value) {
            if (!empty($filter_value)) {
                $active_filters[$id] = $filter_value;
            }
        }
        return $active_filters;

    }

    /**
     * Setup query with active filters
     * @param  [type] $query [description]
     */
    public function setup_post_query($query) {
        if (!$query->is_main_query()) {
            return;
        }
        if (isset($_GET[$this->search_parameter])) {
            $query->query_vars['s'] = $_GET[$this->search_parameter];
        }
        $active_filters = $this->get_active_filters();
        if (empty($active_filters)) {
            return;
        }
        $tax_query = array();
        $meta_query = array();
        foreach ($active_filters as $filter_id => $filter_values) {
            $current_filter = $this->filters[$filter_id];
            if ($current_filter['type'] == 'postmeta') {
                $meta_query[] = array(
                    'key' => $filter_id,
                    'value' => $filter_values,
                    'compare' => $current_filter['operator']
                );
            }
            if ($current_filter['type'] == 'tax') {
                $tax_query[] = array(
                    'taxonomy' => $filter_id,
                    'field' => 'slug',
                    'terms' => $filter_values,
                    'operator' => $current_filter['operator']
                );
            }
        }

        /* Update main query */
        if (!empty($tax_query)) {
            $tax_query['relation'] = 'AND';
            $query->query_vars['tax_query'] = $tax_query;
        }
        if (!empty($meta_query)) {
            $query->query_vars['meta_query'] = $meta_query;
        }
    }

    public function setup_active_filters() {
        if (!isset($_GET[$this->query_key])) {
            return;
        }

        global $wpdb;
        $key = 0;
        /* Get current query */
        $qwhere = array();
        foreach ($this->filters as $filter_id => $filter) {
            foreach ($filter['values'] as $value_id => $value) {
                if (!$this->is_filter_active($filter_id, $value_id)) {
                    continue;
                }
                $qwhere[] = '(idx_key=' . $key . ' AND idx_value="' . esc_sql($value_id) . '")';
            }
            $key++;
        }

        if (empty($qwhere)) {
            return;
        }

        /* Get post ids with current query */
        $ids_posts = "SELECT DISTINCT post_id FROM " . $this->table_index . " WHERE " . implode(' OR ', $qwhere);
        $active_values = "SELECT  DISTINCT  idx_key,idx_value FROM " . $this->table_index . " WHERE post_id IN(" . $ids_posts . ")";
        $filled_filters = $wpdb->get_results($active_values, ARRAY_N);
        $filters_keys = array_keys($this->filters);
        foreach ($filled_filters as $key => $filter) {
            $this->filled_filters[$key] = $filter;
            $this->filled_filters[$key][0] = $filters_keys[$filter[0]];
        }
    }

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    /**
     * Get current post type
     * @return string current post type
     */
    public function get_post_type() {
        global $wp_query;
        if (!$this->post_type && is_object($wp_query) && is_array($wp_query->query) && isset($wp_query->query['post_type'])) {
            return $wp_query->query['post_type'];
        }
        return $this->post_type;
    }

    /**
     * Build an URL with a changed filter
     * @param  string $filter_id    Selected filter
     * @param  string $filter_value Desired value
     * @return string               Filtered URL
     */
    private function build_url_query_with_parameter($filter_id = '', $value_id = '') {

        $filters_query = $this->update_filter_query($filter_id, $value_id);

        /* On current post type */
        $url_with_query = get_post_type_archive_link($this->get_post_type());

        /* Search parameter */
        if (isset($_GET[$this->search_parameter])) {
            $url_with_query = $this->add_parameter_to_url($url_with_query, $this->search_parameter, urlencode($_GET[$this->search_parameter]));
        }

        /* Add current query */
        if (!empty($filters_query)) {
            $url_with_query = $this->add_parameter_to_url($url_with_query, $this->query_key, urlencode(json_encode($filters_query)));
        }

        return $url_with_query;
    }

    public function add_parameter_to_url($url = '', $parameter = '', $value = '') {

        if (empty($parameter)) {
            return $url;
        }

        /* Add parameter */
        $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?');
        $url .= $parameter;

        /* Add value if needed */
        if (!empty($value)) {
            $url .= '=' . $value;
        }

        return $url;
    }

    /**
     * Check if a filter is active
     * @param  string $filter_id    Selected filter
     * @param  string $filter_value Desired value
     * @return boolean              Is active ?
     */
    private function is_filter_active($filter_id = '', $value_id = '') {
        $_active_filters = $this->get_active_filters();
        /* Taxonomy is not even present in active filter */
        if (!array_key_exists($filter_id, $_active_filters)) {
            return false;
        }
        /* Check if present in values */
        return in_array($value_id, $_active_filters[$filter_id]);
    }

    /**
     * Check if a filter can be searched for multiple values
     * @param  string  $filter_id filter id
     * @return boolean           Can multiple ?
     */
    public function can_get_multiple_values($filter_id = '') {
        return !!$this->filters[$filter_id]['multiple_values'];
    }

    /* ----------------------------------------------------------
      Indexation
    ---------------------------------------------------------- */

    public function setup_indexation() {
        /* Dont setup indexation for dynamic filters because too greedy */
        if (!$this->post_type) {
            return false;
        }
        /* Create DB */
        $table_fields = array(
            'post_id' => array(
                'public_name' => 'Post ID',
                'sql' => 'MEDIUMINT UNSIGNED'
            ),
            'idx_key' => array(
                'public_name' => 'Key',
                'sql' => 'MEDIUMINT UNSIGNED'
            ),
            'idx_value' => array(
                'public_name' => 'Value',
                'sql' => 'TEXT'
            )
        );
        include dirname(__FILE__) . '/inc/WPUBaseAdminDatas/WPUBaseAdminDatas.php';
        $this->baseadmindatas = new \wpufilters\WPUBaseAdminDatas();
        $this->baseadmindatas->init(array(
            'handle_database' => false,
            'plugin_id' => 'wpufilters',
            'table_name' => $this->table_index,
            'table_fields' => $table_fields
        ));

        # Admin page
        include dirname(__FILE__) . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $admin_pages = array(
            'main' => array(
                'section' => 'options-general.php',
                'menu_name' => 'Filters',
                'name' => 'Filters',
                'settings_link' => true,
                'settings_name' => 'Settings',
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                )
            )
        );

        $this->adminpages = new \wpufilters\WPUBaseAdminPage();
        $this->adminpages->init(array(
            'id' => 'wpufilters',
            'level' => 'manage_options',
            'basename' => plugin_basename(__FILE__)
        ), $admin_pages);

        # Index
        global $wpdb;
        $this->table_index = $wpdb->prefix . $this->table_index;

        /* Hooks */

        /* - Index at post save */
        add_action('save_post', array(&$this, 'save_post'));

        /* - Clean at post deletion */
        add_action('delete_post', array(&$this, 'deindex_post'));
        add_action('trashed_post', array(&$this, 'deindex_post'));
    }

    public function reindexall_posts() {
        set_time_limit(0);
        $ids = get_posts(array(
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_type' => $this->post_type
        ));
        foreach ($ids as $post_id) {
            $this->index_post($post_id);
        }
    }

    public function save_post($post_id) {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $this->index_post($post_id);
    }

    public function delete_post($post_id) {
        $this->deindex_post($post_id);
    }

    public function deindex_post($post_id) {
        global $wpdb;
        $wpdb->delete($this->table_index, array('post_id' => $post_id), array('%d'));
    }

    public function index_post($post_id) {
        global $wpdb;

        /* Index post values*/
        $key = 0;
        $values = array();
        foreach ($this->filters as $filter_id => $filter) {
            if ($filter['type'] == 'tax') {
                $terms = wp_get_post_terms($post_id, $filter_id);
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        $values[] = array($key, $term->slug);
                    }
                }
            } else {
                $meta_value = get_post_meta($post_id, $filter_id, 1);
                if ($meta_value) {
                    $values[] = array($key, $meta_value);
                }
            }
            $key++;
        }

        /* Clean everything from database for this post */
        $this->deindex_post($post_id);

        /* Insert new values */
        foreach ($values as $value) {
            $wpdb->insert(
                $this->table_index,
                array(
                    'post_id' => $post_id,
                    'idx_key' => $value[0],
                    'idx_value' => $value[1]
                ),
                array(
                    '%d',
                    '%d',
                    '%s'
                )
            );
        }
    }

    public function filter_has_results_current_query($filter_id) {
        /* no results for a specific search */
        if (empty($this->filled_filters)) {
            return (!isset($_GET[$this->query_key]) || $_GET[$this->query_key] == '[]');
        }
        foreach ($this->filled_filters as $filled_filter) {
            if ($filled_filter[0] == $filter_id) {
                return true;
            }
        }
        return false;
    }

    public function filter_var_has_results_current_query($filter_id, $value_id) {
        /* no results for a specific search */
        if (empty($this->filled_filters)) {
            return (!isset($_GET[$this->query_key]) || $_GET[$this->query_key] == '[]');
        }
        foreach ($this->filled_filters as $filled_filter) {
            if ($filled_filter[0] == $filter_id && $filled_filter[1] == $value_id) {
                return true;
            }
        }
        return false;
    }

    /* ----------------------------------------------------------
      Display
    ---------------------------------------------------------- */

    /**
     * Display a search form compatible with the filters
     * @return string HTML Content to be displayed
     */
    public function get_html_search_form() {
        $html = '';

        $template_form = '<form role="search" method="get" class="search-form" action="">
            <input type="hidden" name="' . $this->query_key . '" value="' . esc_attr(json_encode($this->get_active_filters())) . '" />
            <label>
                <span class="screen-reader-text">' . _x('Search for:', 'label') . '</span>
                <input type="search" class="search-field" placeholder="' . esc_attr_x('Search &hellip;', 'placeholder') . '" value="' . get_search_query() . '" name="%s" />
            </label>
            <input type="submit" class="search-submit" value="' . esc_attr_x('Search', 'submit button') . '" />
        </form>';

        $html = apply_filters('wpufilters_tpl_search_form', $template_form);
        $html = sprintf($html, $this->search_parameter);
        return $html;
    }

    /**
     * Display a list of filters
     * @return string   HTML Content to be displayed
     */
    public function get_html_filters() {
        $html = '';
        foreach ($this->filters as $filter_id => $filter) {
            $html_filter = '';

            /* Check if this filter has results with the current query */
            if (!$this->filter_has_results_current_query($filter_id)) {
                continue;
            }

            foreach ($filter['values'] as $value_id => $value) {
                /* Check if there is a result for this var */
                if (!$this->filter_var_has_results_current_query($filter_id, $value_id)) {
                    continue;
                }
                $url = $this->build_url_query_with_parameter($filter_id, $value_id);
                $classname = '';
                if ($this->is_filter_active($filter_id, $value_id)) {
                    $classname = ' class="active"';
                }
                $html_filter .= '<li' . $classname . '><a rel="nofollow" href="' . $url . '"><span>' . $value . '</span></a></li>';
            }
            $html .= '<div class="filter" data-filterid="' . $filter_id . '">' .
                '<div class="filter-inner">' .
                '<div class="filter-name-wrapper"><strong class="filter-name">' . $filter['public_name'] . '</strong></div>' .
                '<ul class="filter-values">' . $html_filter . '</ul>' .
                '</div>' .
                '</div>';
        }
        if (empty($html)) {
            return '';
        }
        return '<div class="wpu-filters__wrapper"><div class="wpu-filters">' . $html . '</div></div>';
    }

    public function get_html_filters_active() {
        $html = '';

        /* Template for active filter */
        $tpl_active_filter = apply_filters('wpufilters_tpl_active_filter', '<a rel="nofollow" href="%s"><span class="name">%s :</span> <strong class="value">%s</strong></a>');

        foreach ($this->filters as $filter_id => $filter) {
            foreach ($filter['values'] as $value_id => $value) {
                if (!$this->is_filter_active($filter_id, $value_id)) {
                    continue;
                }
                $url = $this->build_url_query_with_parameter($filter_id, $value_id);
                $html .= '<li>' . sprintf($tpl_active_filter, $url, $filter['name'], $value) . '</li>';
            }
        }
        if (empty($html)) {
            return '';
        }

        $before_list_filter = apply_filters('wpufilters_tpl_before_list_active_filters', '');
        $after_list_filter = apply_filters('wpufilters_tpl_after_list_active_filters', '');

        return '<div class="wpu-filters-active__wrapper"><div class="wpu-filters-active">' . $before_list_filter . '<ul class="wpu-filters-active__list">' . $html . '</ul>' . $after_list_filter . '</div></div>';

    }

    /* ----------------------------------------------------------
      Admin
    ---------------------------------------------------------- */

    public function page_content__main() {
        echo sprintf(__('<strong>Index status:</strong> %s/%s posts.', 'wpufilters'), $this->get_nb_indexed_posts(), $this->get_nb_posts());
        echo '<hr />';
        submit_button(__('Reindex all', 'wpufilters'), 'button', 'filter_action', true);
    }

    /* Get indexed posts */
    public function get_nb_indexed_posts() {
        global $wpdb;
        $query = "SELECT COUNT(DISTINCT post_id) as nb_posts FROM " . $this->table_index;
        $nb = $wpdb->get_var($query);
        return is_numeric($nb) ? $nb : 0;
    }

    /* Get all posts */
    public function get_nb_posts() {
        $posts = get_posts(array(
            'fields' => 'ids',
            'post_type' => $this->post_type,
            'posts_per_page' => -1
        ));
        $nb_posts = count($posts);
        unset($posts);
        return is_numeric($nb_posts) ? $nb_posts : 0;
    }

    /* Actions
    -------------------------- */

    public function page_action__main() {
        if (isset($_POST['filter_action'])) {
            $this->reindexall_posts();
        }
    }

    /* ----------------------------------------------------------
      Install
    ---------------------------------------------------------- */

    public function uninstall() {
        if (is_object($this->baseadmindatas)) {
            $this->baseadmindatas->drop_database();
        }
    }
}

$WPUFilters = new WPUFilters();

register_deactivation_hook(__FILE__, 'wpufilters_deactivation_hook');
function wpufilters_deactivation_hook() {
    global $WPUFilters;
    $WPUFilters->uninstall();
}
