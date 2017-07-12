<?php

namespace CachePurge\Plugin;

use CachePurge\Api\Api;

/**
 * Class Plugin
 * @package CachePurge\Plugin
 */
abstract class Plugin
{
    const ENABLED_OPTION = 'cachepurge-enabled';
    const MAX_AGE_OPTION = 'cachepurge-max-age';
    const SETTINGS_PAGE = 'cachepurge-settings-page';
    const SETTINGS_SECTION = 'cachepurge-settings';

    protected $purgeActions = [
        'deleted_post',
        'edit_post',
        'delete_attachment'
    ];

    protected $failed = false;

    /**
     * @return Api
     */
    abstract protected function _getApi();

    abstract protected function isConfigured();

    public function onApiEvent($event, $message)
    {
        if ($event == Api::ERROR)
            $this->failed = true;
        $message = sprintf("[%s] [%s]\n%s\n", date('Y-m-d H:i:sO'), strtoupper($event), $message);
        error_log($message, 3, WP_CONTENT_DIR . '/cachepurge.log');
    }

    /**
     * @return Api
     */
    public function getApi()
    {
        $api = $this->_getApi();
        $api->on('*', [$this, 'onApiEvent']);
        return $api;
    }

    public function purgeEverything()
    {
        $urls = trailingslashit(get_site_url()) . '*';
        $urls = apply_filters('cachepurge_urls', $urls);

        return $this->getApi()->invalidate($urls);
    }

    public function purgeRelevant($postId)
    {
        $validPostStatus = ['publish', 'trash'];
        $thisPostStatus = get_post_status($postId);

        if (get_permalink($postId) != true || !in_array($thisPostStatus, $validPostStatus)) {
            return null;
        }

        if (is_int(wp_is_post_autosave($postId)) || is_int(wp_is_post_revision($postId))) {
            return null;
        }

        $saved_post = get_post($postId);
        if (is_a($saved_post, 'WP_Post') == false) {
            return null;
        }

        $urls = $this->getPostRelatedLinks($postId);
        $urls = apply_filters('cachepurge_urls', $urls);

        return $this->getApi()->invalidate($urls);
    }

    public function getPostRelatedLinks($postId)
    {
        $listofurls = [];
        $post_type = get_post_type($postId);

        //Purge taxonomies terms URLs
        $post_type_taxonomies = get_object_taxonomies($post_type);

        foreach ($post_type_taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (!is_wp_error($term_link)) {
                    array_push($listofurls, $term_link);
                    array_push($listofurls, $term_link . 'page/*');
                }
            }
        }

        // Author URL
        array_push(
            $listofurls,
            get_author_posts_url(get_post_field('post_author', $postId)),
            get_author_posts_url(get_post_field('post_author', $postId)) . 'page/*',
            get_author_feed_link(get_post_field('post_author', $postId))
        );

        // Archives and their feeds
        if (get_post_type_archive_link($post_type) == true) {
            array_push(
                $listofurls,
                get_post_type_archive_link($post_type),
                get_post_type_archive_feed_link($post_type)
            );
        }

        // Post URL
        array_push($listofurls, get_permalink($postId));

        // Also clean URL for trashed post.
        if (get_post_status($postId) == 'trash') {
            $trashpost = get_permalink($postId);
            $trashpost = str_replace('__trashed', '', $trashpost);
            array_push($listofurls, $trashpost, $trashpost . 'feed/');
        }

        // Feeds
        array_push(
            $listofurls,
            get_bloginfo_rss('rdf_url'),
            get_bloginfo_rss('rss_url'),
            get_bloginfo_rss('rss2_url'),
            get_bloginfo_rss('atom_url'),
            get_bloginfo_rss('comments_rss2_url'),
            get_post_comments_feed_link($postId)
        );

        // Home Page and (if used) posts page
        $pageLink = get_permalink(get_option('page_for_posts'));
        if (is_string($pageLink) && !empty($pageLink) && get_option('show_on_front') == 'page') {
            array_push($listofurls, $pageLink);
        }

        return $listofurls;
    }

    public function init()
    {
        // set Cache-Control headers
        add_action('template_redirect', [$this, 'cache_control_send_headers']);

        // add admin bar button 'Clear Cache'
        add_action('admin_bar_menu', [$this, 'admin_bar_item'], 100);

        add_action('network_admin_menu', [$this, 'menu_item']);
        add_action('admin_menu', [$this, 'menu_item']); // fires first, before admin_init
        add_action('admin_init', [$this, 'setup_settings']);
        add_action('admin_notices', [$this, 'cleared_cache_notice']);

        // ajax action to clear cache
        add_action('wp_ajax_cachepurge_clear_cache_full', [$this, 'action_clear_cache_full']);

        // Load Automatic Cache Purge
        // add_action('switch_theme', [$this, 'purgeEverything']);
        // add_action('customize_save_after', [$this, 'purgeEverything']);
        if ($this->isConfigured()) {
            foreach ($this->purgeActions as $action) {
                add_action($action, [$this, 'purgeRelevant'], 10, 2);
            }
            add_action('save_post', array($this, 'save_post'));
        }
    }

    public function cache_control_send_headers()
    {
        $current_blog_id = get_current_blog_id();
        switch_to_blog(BLOG_ID_CURRENT_SITE);
        if ($max_age = get_option(Plugin::MAX_AGE_OPTION))
            header('Cache-Control: public; max-age: ' . $max_age);
        switch_to_blog($current_blog_id);
    }

    public function admin_bar_item($wp_admin_bar)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        /*
        $wp_admin_bar->add_node([
            'id' => 'cachepurge',
            'title' => 'Clear Cache ' . trailingslashit(get_home_url()) . '*',
            'href' => wp_nonce_url(admin_url('admin-ajax.php?action=cachepurge_clear_cache_full&source=adminbar'), 'cachepurge-clear-cache-full', 'cachepurge_nonce'),
            'meta' => ['title' => 'Clear Cache'],
            'parent' => 'top-secondary'
        ]);
        */
    }

    public function menu_item()
    {
        add_menu_page(
            'CachePurge Settings',
            'Cache Purge',
            'manage_options',
            Plugin::SETTINGS_PAGE,
            [$this, 'settings_page']
        );
    }

    public function action_clear_cache_full()
    {
        check_ajax_referer('cachepurge-clear-cache-full', 'cachepurge_nonce');
        $result = $this->purgeEverything();
        header("Location: " . add_query_arg('cachepurge-cache-cleared', $result, $_SERVER['HTTP_REFERER']));
    }

    public function save_post()
    {
        add_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
    }

    public function add_notice_query_var($location)
    {
        remove_filter('redirect_post_location', array($this, 'add_notice_query_var'), 99);
        return add_query_arg(array('cachepurge-cache-cleared' => (int)!$this->failed), $location);
    }

    public function cleared_cache_notice()
    {
        if (!empty($_GET['cachepurge-cache-cleared']) && $_GET['cachepurge-cache-cleared'] == 1) :
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Cache cleared successfully</p>
            </div>
            <?php
        elseif (isset($_GET['cachepurge-cache-cleared'])) :
            ?>
            <div class="notice notice-error is-dismissible">
                <p>Failed to clear cache</p>
            </div>
            <?php
        endif;
    }

    public function setup_settings()
    {
        add_settings_section(
            Plugin::SETTINGS_SECTION,
            'Settings',
            null,
            Plugin::SETTINGS_PAGE
        );

        add_settings_field(
            Plugin::ENABLED_OPTION,
            'Enabled',
            function () {
                echo $this->enabled_checkbox_javascript(Plugin::ENABLED_OPTION);
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );
        register_setting(Plugin::SETTINGS_PAGE, Plugin::ENABLED_OPTION, 'intval');

        if (!is_multisite() || get_current_blog_id() === BLOG_ID_CURRENT_SITE) {
            add_settings_field(
                Plugin::MAX_AGE_OPTION,
                'Max Age',
                function () {
                    $max_age = get_option(Plugin::MAX_AGE_OPTION);
                    if (!$max_age)
                        $max_age = 31536000;
                    echo '<input name="' . Plugin::MAX_AGE_OPTION . '" value="' . (int)$max_age . '">';
                    echo '<p>Set this option to zero to disable sending Cache-Control header</p>';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            register_setting(Plugin::SETTINGS_PAGE, Plugin::MAX_AGE_OPTION, 'intval');
        }
    }

    public function enabled_checkbox_javascript($field)
    {
        $result = '<input name="' . $field . '" type="hidden" value="' . get_option($field) . '">';
        $result .= '<input id="enabled-checkbox" type="checkbox" ' . (get_option($field) ? 'checked' : '') . '>';
        $result .= '<script type="text/javascript">';

        $result .= <<<JS
            (function ($) {
                function setState(enabled) {
                    $("[name={$field}]").val(enabled ? "1" : "0");
                    $("input").each(function (i, el) {
                        if (!$(el).parents('tr').find("[name={$field}]").length) {
                            console.log(el);
                            if (enabled) {
                                $(el).parents('tr').show();
                            } else {
                                $(el).parents('tr').hide();
                            }
                        }
                    });
                }
                $("#enabled-checkbox").on("click", function () {
                    setState($("#enabled-checkbox").is(":checked"));
                });
                $(document).ready(function () {
                    if ($("#enabled-checkbox").length)
                        setState($("#enabled-checkbox").is(":checked"));
                });
            })(jQuery);

JS;

        $result .= '</script>';
        return $result;
    }

    public function override_checkbox_javascript($field)
    {
        $result = '<input name="' . $field . '" type="hidden" value="' . get_option($field) . '">';
        $result .= '<input id="override-checkbox" type="checkbox" ' . (get_option($field) ? 'checked' : '') . '>';
        $result .= '<script type="text/javascript">';

        $result .= <<<JS
            (function ($) {
                function setState(disabled) {
                    $("[name={$field}]").val(disabled ? "0" : "1");
                    $("input:not([type]), input[type='text'], input[type='password']").prop("disabled", disabled);
                }
                $("#override-checkbox").on("click", function () {
                    setState(!$("#override-checkbox").is(":checked"));
                });
                $(document).ready(function () {
                    if ($("#override-checkbox").length)
                        setState(!$("#override-checkbox").is(":checked"));
                });
            })(jQuery);

JS;

        $result .= '</script>';
        return $result;
    }

    public function settings_page()
    {
        echo '<form action="' . get_site_url(null, '/wp-admin/options.php') . '" method="POST">';
        echo '<input type="hidden" name="override-settings">';
        settings_fields(Plugin::SETTINGS_PAGE);
        do_settings_sections(Plugin::SETTINGS_PAGE);
        submit_button();
        echo '</form>';
    }
}