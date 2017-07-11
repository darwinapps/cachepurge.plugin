<?php
/**
 * Plugin Name: Cache Purge
 * Plugin URI: https://wordpress.org/plugins/darwin-cachepurge/
 * Plugin Author: Aleksandr Guidrevitch
 * Version: 0.0.2
 * Author: DarwinApps, Aleksandr Guidrevitch
 * Author URI: http://darwinapps.com/
 * Description: Selective cache purge on publish
 */

namespace CachePurge {

    /**
     * Interface ApiInterface
     * @package CachePurge
     */
    abstract class Api
    {
        const ERROR = 'error';
        const DEBUG = 'debug';

        private $_events = array();

        /**
         * @param string $event
         * @return void
         */
        public function emit($event)
        {
            $args = func_get_args();
            if (isset($this->_events[$event])) {
                foreach ($this->_events[$event] as $callback) {
                    call_user_func_array($callback, $args);
                }
            }
            if (isset($this->_events['*'])) {
                foreach ($this->_events['*'] as $callback) {
                    call_user_func_array($callback, $args);
                }
            }
        }

        /**
         * @param string $event event name
         * @param callable $callback function to call
         */
        public function on($event = null, $callback)
        {
            if (!is_callable($callback))
                throw new \Exception("Callback $callback is not callable");

            if (!$event)
                $event = '*';

            if (!isset($this->_events[$event]))
                $this->_events[$event] = array();

            $this->_events[$event][] = $callback;
        }

        public function off($event = null, $callback = null)
        {
            $callbackName = null;
            if ($callback && !is_callable($callback)) {
                throw new \Exception("Callback $callback is not callable");
            } elseif ($callback) {
                is_callable($callback, true, $callbackName);
            }

            $events = $event
                ? array($event)
                : array_keys($this->_events);

            foreach ($events as $event) {
                if (!empty($this->_events[$event])) {
                    if ($callback) {
                        foreach ($this->_events[$event] as $i => $handler) {
                            if ($handler === $callback) {
                                unset($this->_events[$event][$i]);
                            }
                        }
                    } else {
                        unset($this->_events[$event]);
                    }
                }
            }
        }

        abstract public function invalidate(array $urls);
    }

    /**
     * Class CloudFront
     * @package CachePurge
     */
    class CloudFrontAPI extends Api
    {
        protected $access_key;
        protected $secret_key;
        protected $distribution_id;
        protected $timeout = 10;

        public function setAccessKey($value)
        {
            $this->access_key = $value;
        }

        public function setSecretKey($value)
        {
            $this->secret_key = $value;
        }

        public function setDistributionId($value)
        {
            $this->distribution_id = $value;
        }

        public function callApi($method, $url, $xml = '')
        {
            if (!$this->access_key || !$this->secret_key || !$this->distribution_id) {
                $this->emit(Api::ERROR, "CloudFront API is not configured properly");
                return false;
            }

            $len = strlen($xml);
            $date = gmdate('D, d M Y G:i:s T');
            $sig = base64_encode(hash_hmac('sha1', $date, $this->secret_key, true));
            $msg = "{$method} {$url} HTTP/1.0\r\n";
            $msg .= "Host: cloudfront.amazonaws.com\r\n";
            $msg .= "Date: {$date}\r\n";
            $msg .= "Content-Type: text/xml; charset=UTF-8\r\n";
            $msg .= "Authorization: AWS {$this->access_key}:{$sig}\r\n";
            $msg .= "Content-Length: {$len}\r\n\r\n";
            $msg .= $xml;
            $fp = @fsockopen('ssl://cloudfront.amazonaws.com', 443, $errno, $errstr, $this->timeout);
            if ($fp) {
                fwrite($fp, $msg);
                $resp = '';
                while (!feof($fp)) {
                    $resp .= fread($fp, 65536);
                }
                fclose($fp);
                if (!preg_match('#^HTTP/1.1 20[01]#', $resp) || !preg_match('#<Id>(.*?)</Id>#m', $resp, $matches)) {
                    $this->emit(Api::ERROR, "Failed to create invalidation");
                    $this->emit(Api::ERROR, "Request: $msg");
                    $this->emit(Api::ERROR, "Response: $resp");
                    return false;
                } else {
                    $this->emit(Api::DEBUG, "Request: $msg");
                    $this->emit(Api::DEBUG, "Response: $resp");
                }
                return $resp;
            }
            $this->emit(Api::ERROR, "Connection to CloudFront API failed: {$errno} {$errstr}");
            return false;
        }

        /*
        public function checkInvalidation($id)
        {
            if (false !== ($resp = $this->callApi('GET', "/2010-11-01/distribution/{$this->distribution_id}/invalidation/{$id}"))) {
                if (preg_match('#<Status>(.*?)</Status>#m', $resp, $matches)) {
                    return $matches[1];
                }
            }
            return false;
        }
        */

        public function invalidate(array $urls)
        {
            $epoch = date('U');
            $paths = join("", array_map(function ($url) {
                return "<Path>$url</Path>";
            }, $urls));

            $xml = "<InvalidationBatch>{$paths}<CallerReference>{$this->distribution_id}{$epoch}</CallerReference></InvalidationBatch>";

            if (false === ($resp = $this->callApi('POST', "/2010-11-01/distribution/{$this->distribution_id}/invalidation", $xml))) {
                return false;
            }

            return true;
        }
    }

    /* example nxing configuration section

    location ~ /purge(.*) {
        selective_cache_purge_query "$1";
    }

    location ~ /mpurge {
        client_body_buffer_size 128k;
        client_max_body_size 128k;

        content_by_lua_block {
            ngx.req.read_body()
            local data = ngx.req.get_body_data()

            local http = require "resty.http"
            local httpc = http.new()
            httpc:set_timeouts(100, 100, 5000)

            for str in string.gmatch(data, "([^\r\n]+)") do
                local res, err = httpc:request_uri("http://127.0.0.1/purge" .. str)
                if not res then
                    ngx.say("failed to purge " .. str .. ": ", err)
                else
                    ngx.say(res.body)
                end
            end

            return
        }
    }
     */

    /**
     * Class CloudFront
     * @package CachePurge
     */
    class NginxAPI extends Api
    {
        protected $url;
        protected $username;
        protected $password;
        protected $timeout = 10;

        public function setUrl($value)
        {
            $this->url = $value;
        }

        public function setUsername($value)
        {
            $this->username = $value;
        }

        public function setPassword($value)
        {
            $this->password = $value;
        }

        public function callApi($url, $body)
        {
            if (!$this->url || false == parse_url($this->url)) {
                $this->emit(Api::ERROR, "Nginx API is not configured properly");
                return false;
            }

            $host = parse_url($url, PHP_URL_HOST);
            $msg = "POST {$url} HTTP/1.0\r\n";
            $msg .= "Host: {$host}\r\n";
            $msg .= "Content-Length: " . strlen($body) . "\r\n";
            if ($this->username)
                $msg .= 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . "\r\n";
            $msg .= "\r\n";
            $msg .= $body;

            $fp = strtolower(parse_url($url, PHP_URL_SCHEME)) == 'https'
                ? @fsockopen('ssl://' . $host, 443, $errno, $errstr, $this->timeout)
                : @fsockopen($host, 80, $errno, $errstr, $this->timeout);

            if ($fp) {
                fwrite($fp, $msg);
                $resp = '';
                while (!feof($fp)) {
                    $resp .= fread($fp, 131072);
                }
                if (!preg_match('#^HTTP/1.1 20[01]#', $resp)) {
                    $this->emit(Api::ERROR, "Failed to invalidate");
                    $this->emit(Api::ERROR, "Request: $msg");
                    $this->emit(Api::ERROR, "Response: $resp");
                    return false;
                } else {
                    $this->emit(Api::DEBUG, "Request: $msg");
                    $this->emit(Api::DEBUG, "Response: $resp");
                }
                fclose($fp);
                return $resp;
            }
            $this->emit(API::ERROR, "Connection to {$url} failed: {$errno} {$errstr}");
            return false;
        }

        public function invalidate(array $urls)
        {
            $result = true;
            foreach (preg_split('/\s*;\s*/', $this->url) as $apiUrl) {
                if (false === ($resp = $this->callApi($apiUrl, join("\n", $urls)))) {
                    $result = false;
                }
            }
            return $result;
        }
    }

    /**
     * Class Plugin
     * @package CachePurge
     */
    abstract class Plugin
    {
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

        public function onApiEvent($event, $message)
        {
            if ($event == Api::ERROR)
                $this->failed = true;
            error_log(
                sprintf("[%s] [%s]\n%s\n", date('Y-m-d H:i:sO'), strtoupper($event), $message),
                3,
                WP_CONTENT_DIR . '/cachepurge.log'
            );
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

            // add admin bar button 'Clear Cloudfront Cache'
            add_action('admin_bar_menu', [$this, 'admin_bar_item'], 100);
            //add_action('admin_notices', [$this, 'cleared_cache_notice']);

            add_action('network_admin_menu', [$this, 'menu_item']);
            add_action('admin_menu', [$this, 'menu_item']); // fires first, before admin_init
            add_action('admin_init', [$this, 'setup_settings']);
            add_action('admin_notices', [$this, 'cleared_cache_notice']);
            add_action('save_post', array($this, 'save_post'));

            // ajax action to clear cache
            add_action('wp_ajax_cachepurge_clear_cache_full', [$this, 'action_clear_cache_full']);

            // Load Automatic Cache Purge
            add_action('switch_theme', [$this, 'purgeEverything']);
            add_action('customize_save_after', [$this, 'purgeEverything']);
            foreach ($this->purgeActions as $action) {
                add_action($action, [$this, 'purgeRelevant'], 10, 2);
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

            $wp_admin_bar->add_node([
                'id' => 'cachepurge',
                'title' => 'Clear Cache ' . trailingslashit(get_home_url()) . '*',
                'href' => wp_nonce_url(admin_url('admin-ajax.php?action=cachepurge_clear_cache_full&source=adminbar'), 'cachepurge-clear-cache-full', 'cachepurge_nonce'),
                'meta' => ['title' => 'Clear CloudFront Cache'],
                'parent' => 'top-secondary'
            ]);
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

        public function override_javascript($field)
        {
            $result = '<input name="' . $field . '" type="hidden" value="' . get_option($field) . '">';
            $result .= '<input id="override-checkbox" type="checkbox" checked="' . get_option($field) . '">';
            $result .= '<script type="text/javascript">';

            $result .= <<<JS
            function setState(disabled) {
                jQuery("[name={$field}]").val(disabled ? "0" : "1");
                jQuery("input:not([type]), input[type='text'], input[type='password']").prop("disabled", disabled);
            }
            jQuery("#override-checkbox").on("click", function () {
                setState(!jQuery("#override-checkbox").is(":checked"));
            });
            if (jQuery("#override-checkbox").length)
                setState(!jQuery("#override-checkbox").is(":checked"));

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

    /**
     * Class CloudFrontPlugin
     * @package CachePurge
     */
    class CloudFrontPlugin extends Plugin
    {

        const ACCESS_KEY_OPTION = 'cachepurge-cloudfront-access-key';
        const SECRET_KEY_OPTION = 'cachepurge-cloudfront-secret-key';
        const DISTRIBUTION_ID_OPTION = 'cachepurge-cloudfront-distribution-id';
        const OVERRIDE_OPTION = 'cachepurge-cloudfront-override';

        protected function _getApi()
        {
            static $api;

            if ($api)
                return $api;

            $current_blog_id = get_current_blog_id();
            if (!get_option(NginxPlugin::OVERRIDE_OPTION))
                switch_to_blog(BLOG_ID_CURRENT_SITE);

            $api = new CloudFrontAPI();
            $api->setAccessKey(get_option(CloudFrontPlugin::ACCESS_KEY_OPTION));
            $api->setSecretKey(get_option(CloudFrontPlugin::SECRET_KEY_OPTION));
            $api->setDistributionId(get_option(CloudFrontPlugin::DISTRIBUTION_ID_OPTION));

            switch_to_blog($current_blog_id);

            return $api;
        }

        public function setup_settings()
        {
            parent::setup_settings();

            add_settings_field(
                CloudFrontPlugin::ACCESS_KEY_OPTION,
                'Access Key',
                function () {
                    echo '<input name="' . CloudFrontPlugin::ACCESS_KEY_OPTION . '" value="' . get_option(CloudFrontPlugin::ACCESS_KEY_OPTION) . '">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            add_settings_field(
                CloudFrontPlugin::SECRET_KEY_OPTION,
                'Secret Key',
                function () {
                    echo '<input name="' . CloudFrontPlugin::SECRET_KEY_OPTION . '" value="' . get_option(CloudFrontPlugin::SECRET_KEY_OPTION) . '">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            add_settings_field(
                CloudFrontPlugin::DISTRIBUTION_ID_OPTION,
                'Distribution Id',
                function () {
                    echo '<input name="' . CloudFrontPlugin::DISTRIBUTION_ID_OPTION . '" value="' . get_option(CloudFrontPlugin::DISTRIBUTION_ID_OPTION) . '">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            register_setting(Plugin::SETTINGS_PAGE, CloudFrontPlugin::ACCESS_KEY_OPTION, 'wp_filter_nohtml_kses');
            register_setting(Plugin::SETTINGS_PAGE, CloudFrontPlugin::SECRET_KEY_OPTION, 'wp_filter_nohtml_kses');
            register_setting(Plugin::SETTINGS_PAGE, CloudFrontPlugin::DISTRIBUTION_ID_OPTION, 'wp_filter_nohtml_kses');

            if (get_current_blog_id() !== BLOG_ID_CURRENT_SITE) {
                add_settings_field(
                    CloudFrontPlugin::OVERRIDE_OPTION,
                    'Override global settings',
                    function () {
                        echo $this->override_javascript(CloudFrontPlugin::OVERRIDE_OPTION);
                    },
                    Plugin::SETTINGS_PAGE,
                    Plugin::SETTINGS_SECTION
                );
                register_setting(Plugin::SETTINGS_PAGE, CloudFrontPlugin::OVERRIDE_OPTION, 'wp_filter_nohtml_kses');
            }
        }
    }

    class NginxPlugin extends Plugin
    {
        const URL_OPTION = 'cachepurge-nginx-url';
        const USERNAME_OPTION = 'cachepurge-nginx-username';
        const PASSWORD_OPTION = 'cachepurge-nginx-password';
        const OVERRIDE_OPTION = 'cachepurge-nginx-override';

        protected function _getApi()
        {
            static $api;

            if ($api)
                return $api;

            $current_blog_id = get_current_blog_id();
            if (!get_option(NginxPlugin::OVERRIDE_OPTION))
                switch_to_blog(BLOG_ID_CURRENT_SITE);

            $api = new NginxApi();
            $api->setUrl(get_option(NginxPlugin::URL_OPTION));
            $api->setUsername(get_option(NginxPlugin::USERNAME_OPTION));
            $api->setPassword(get_option(NginxPlugin::PASSWORD_OPTION));

            switch_to_blog($current_blog_id);

            return $api;
        }

        public function setup_settings()
        {
            parent::setup_settings();

            add_settings_field(
                NginxPlugin::URL_OPTION,
                'URL',
                function () {
                    echo '<input name="' . NginxPlugin::URL_OPTION . '" value="' . get_option(NginxPlugin::URL_OPTION) . '" size="100">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            add_settings_field(
                NginxPlugin::USERNAME_OPTION,
                'Username',
                function () {
                    echo '<input name="' . NginxPlugin::USERNAME_OPTION . '" value="' . get_option(NginxPlugin::USERNAME_OPTION) . '">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            add_settings_field(
                NginxPlugin::PASSWORD_OPTION,
                'Password',
                function () {
                    echo '<input name="' . NginxPlugin::PASSWORD_OPTION . '" value="' . get_option(NginxPlugin::PASSWORD_OPTION) . '">';
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );

            register_setting(Plugin::SETTINGS_PAGE, NginxPlugin::URL_OPTION, 'wp_filter_nohtml_kses');
            register_setting(Plugin::SETTINGS_PAGE, NginxPlugin::USERNAME_OPTION, 'wp_filter_nohtml_kses');
            register_setting(Plugin::SETTINGS_PAGE, NginxPlugin::PASSWORD_OPTION, 'wp_filter_nohtml_kses');

            if (get_current_blog_id() !== BLOG_ID_CURRENT_SITE) {
                add_settings_field(
                    NginxPlugin::OVERRIDE_OPTION,
                    'Override global settings',
                    function () {
                        echo $this->override_javascript(NginxPlugin::OVERRIDE_OPTION);
                    },
                    Plugin::SETTINGS_PAGE,
                    Plugin::SETTINGS_SECTION
                );
                register_setting(Plugin::SETTINGS_PAGE, NginxPlugin::OVERRIDE_OPTION, 'wp_filter_nohtml_kses');
            }
        }
    }
}

namespace {

    // Exit if accessed directly
    if (!defined('ABSPATH')) {
        exit;
    }

    // not needed for CloudFlare, they rely on full urls,
    // depends on NGINX selective cache purge setup
    add_filter('cachepurge_urls', function ($urls) {
        $urls = str_replace('http://' . $_SERVER['HTTP_HOST'] . '/', "/", $urls);
        $urls = str_replace('https://' . $_SERVER['HTTP_HOST'] . '/', "/", $urls);
        return $urls;
    }, 10, 1);

    // Initiliaze Hooks class which contains WordPress hook functions
    $hooks = new CachePurge\NginxPlugin();
    $hooks->init();
}

