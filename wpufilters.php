<?php

/*
Plugin Name: WPU Filters
Plugin URI: https://github.com/WordPressUtilities/wpufilters
Description: Simple filters for WordPress
Version: 0.2.0
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUFilters {
    private $plugin_version = '0.2.0';
    private $query_key = 'wpufilters_query';
    private $filters = array();
    private $filters_types = array(
        'postmeta',
        'tax'
    );

    public function __construct() {
        if (is_admin()) {
            return;
        }
        add_filter('init', array(&$this, 'init'), 50);
    }

    /**
     * Init filters
     */
    public function init() {
        /* Setup filters */
        $this->filters = $this->set_filters(apply_filters('wpufilters_filters', array()));

        /* Hooks */
        add_action('pre_get_posts', array(&$this, 'setup_post_query'));

        /* Auto update */
        include dirname(__FILE__) . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpufilters\WPUBaseUpdate(
            'WordPressUtilities',
            'wpufilters',
            $this->plugin_version);
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
            /* Set correct values (used only for postmetas) */
            if (!isset($filter['values']) || !is_array($filters['values'])) {
                $filter['values'] = array();
                if ($filter['type'] == 'postmeta') {
                    $filter['values'] = array(__('No'), __('Yes'));
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

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    /**
     * Build an URL with a changed filter
     * @param  string $filter_id    Selected filter
     * @param  string $filter_value Desired value
     * @return string               Filtered URL
     */
    private function build_url_query_with_parameter($filter_id = '', $value_id = '') {
        $filters_query = $this->update_filter_query($filter_id, $value_id);
        /* On current post type */
        $url_with_query = get_post_type_archive_link(get_post_type());
        /* Add current query */
        if (!empty($filters_query)) {
            $url_with_query .= '?' . $this->query_key . '=' . urlencode(json_encode($filters_query));
        }
        return $url_with_query;
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
      Display
    ---------------------------------------------------------- */

    /**
     * Display a list of filters
     * @return string   HTML Content to be displayed
     */
    public function get_html_filters() {
        $html = '';
        foreach ($this->filters as $filter_id => $filter) {
            $html_filter = '';
            foreach ($filter['values'] as $value_id => $value) {
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
}

$WPUFilters = new WPUFilters();
