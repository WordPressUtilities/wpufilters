# WPU Filters

Simple filters for WordPress

## How to install :

* Put this folder to your wp-content/plugins/ folder.
* Activate the plugin in "Plugins" admin section.

### Add filters

```php
add_filter('wpufilters_filters', 'wputh__wpufilters_filters', 10, 1);
function wputh__wpufilters_filters($filters = array()) {

    /* Taxonomies */
    $filters =  array(
        'projet' => array(
            'name' => 'Projet',
            'type' => 'tax'
        ),
        'difficulte' => array(
            'name' => 'Difficulté',
            'type' => 'tax'
        )
    );

    /* Postmetas */
    $filters['meta_key_checkbox'] = array(
        'multiple_values' => false,
        'name' => 'Ma Meta'
    );
    $filters['meta_key_values'] = array(
        'multiple_values' => false,
        'name' => 'Ma Meta',
        'values' => array(
            'value_1' => 'Internet',
            'value_2' => 'Filter',
            'value_3' => 'Wi-Fi'
        )
    );

    return $filters;
}
```

### Display filters on an post type archive page :

```php
if (is_object($WPUFilters)) {
    /* All filters */
    echo $WPUFilters->get_html_filters();
    /* Current filters */
    echo $WPUFilters->get_html_filters_active();
}
```
