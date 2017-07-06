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
    interface ApiInterface
    {
        public function invalidate(array $urls);
    }

    /**
     * Class CloudFront
     * @package CachePurge
     */
    class CloudFrontAPI implements ApiInterface
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
                error_log("CloudFront API is not configured properly");
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
            $fp = fsockopen('ssl://cloudfront.amazonaws.com', 443, $errno, $errstr, $this->timeout);
            if ($fp) {
                fwrite($fp, $msg);
                $resp = '';
                while (!feof($fp)) {
                    $resp .= fread($fp, 65536);
                }
                fclose($fp);
                if (!preg_match('#^HTTP/1.1 20[01]#', $resp)) {
                    error_log($xml);
                    error_log($resp);
                    return false;
                }
                return $resp;
            }
            error_log("Connection to CloudFront API failed: {$errno} {$errstr}");
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

            if (false !== ($resp = $this->callApi('POST', "/2010-11-01/distribution/{$this->distribution_id}/invalidation", $xml))) {
                if (!preg_match('#<Id>(.*?)</Id>#m', $resp, $matches)) {
                    error_log($xml);
                    error_log($resp);
                    return false;
                }
            }
            return false;
        }
    }

    /**
     * Class CloudFront
     * @package CachePurge
     */
    class NginxAPI implements ApiInterface
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

        public function callApi($url)
        {
            if (!$this->url) {
                error_log("Nginx API is not configured properly");
                return false;
            }

            $host = parse_url($url, PHP_URL_HOST);
            $msg = "GET {$url} HTTP/1.0\r\n";
            $msg .= "Connection: keep-alive\r\n";
            $msg .= "Host: {$host}\r\n\r\n";
            if ($this->username)
                $msg .= 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . "\r\n";
            $msg .= "\r\n";

            $fp = strtolower(parse_url($url, PHP_URL_SCHEME)) == 'https'
                ? pfsockopen('ssl://' . $host, 443, $errno, $errstr, $this->timeout)
                : pfsockopen($host, 80, $errno, $errstr, $this->timeout);

            if ($fp) {
                fwrite($fp, $msg);
                $resp = '';
                while (!feof($fp)) {
                    $resp .= fread($fp, 65536);
                }
                fclose($fp);
                return $resp;
            }
            error_log("Connection to {$this->url}{$url}: {$errno} {$errstr}");
            return false;
        }

        public function invalidate(array $urls)
        {
            $result = true;
            foreach ($urls as $url) {
                foreach (preg_split('/\s*;\s*/', $this->url) as $apiUrl) {
                    if (false === ($resp = $this->callApi($apiUrl . $url))) {
                        $result = false;
                    }
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

        /**
         * @return ApiInterface
         */
        abstract function getApi();

        public function purgeEverything()
        {
            return $this->getApi()->invalidate(['/*']);
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
                        array_push($listofurls, $term_link . '/page/*');
                    }
                }
            }

            // Author URL
            array_push(
                $listofurls,
                get_author_posts_url(get_post_field('post_author', $postId)),
                get_author_posts_url(get_post_field('post_author', $postId)) . '/page/*',
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
            add_action('admin_notices', [$this, 'cleared_cache_notice']);

            add_action('admin_menu', [$this, 'menu_item']); // fires first, before admin_init
            add_action('admin_init', [$this, 'setup_settings']);

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
            if ($max_age = get_option(Plugin::MAX_AGE_OPTION))
                header('Cache-Control: public; max-age: ' . $max_age);
        }

        public function admin_bar_item($wp_admin_bar)
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $wp_admin_bar->add_node([
                'id' => 'cachepurge',
                'title' => 'Clear Cache',
                'href' => wp_nonce_url(admin_url('admin-ajax.php?action=cachepurge_clear_cache_full&source=adminbar'), 'cachepurge-clear-cache-full', 'cachepurge_nonce'),
                'meta' => ['title' => 'Clear CloudFront Cache'],
                'parent' => 'top-secondary'
            ]);
        }

        public function menu_item()
        {
            add_options_page(
                'CachePurge Settings',
                'CachePurge Settings',
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


        public function settings_page()
        {
            echo '<form action="options.php" method="POST">';
            settings_fields(Plugin::SETTINGS_PAGE);
            do_settings_sections(Plugin::SETTINGS_PAGE);
            submit_button();
            echo '</form>';
        }
    }

    class CloudFrontPlugin extends Plugin
    {

        const ACCESS_KEY_OPTION = 'cachepurge-cloudfront-access-key';
        const SECRET_KEY_OPTION = 'cachepurge-cloudfront-secret-key';
        const DISTRIBUTION_ID_OPTION = 'cachepurge-cloudfront-distribution-id';

        public function getApi()
        {
            static $api;

            if ($api)
                return $api;

            $api = new CloudFrontAPI();
            $api->setAccessKey(get_option(CloudFrontPlugin::ACCESS_KEY_OPTION));
            $api->setSecretKey(get_option(CloudFrontPlugin::SECRET_KEY_OPTION));
            $api->setDistributionId(get_option(CloudFrontPlugin::DISTRIBUTION_ID_OPTION));

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
        }
    }

    class NginxPlugin extends Plugin
    {
        const URL_OPTION = 'cachepurge-nginx-url';
        const USERNAME_OPTION = 'cachepurge-nginx-username';
        const PASSWORD_OPTION = 'cachepurge-nginx-password';

        public function getApi()
        {
            static $api;

            if ($api)
                return $api;

            $api = new NginxApi();
            $api->setUrl(get_option(NginxPlugin::URL_OPTION));
            $api->setUsername(get_option(NginxPlugin::USERNAME_OPTION));
            $api->setPassword(get_option(NginxPlugin::PASSWORD_OPTION));

            return $api;
        }

        public function setup_settings()
        {
            parent::setup_settings();

            add_settings_field(
                NginxPlugin::URL_OPTION,
                'URL',
                function () {
                    echo '<input name="' . NginxPlugin::URL_OPTION . '" value="' . get_option(NginxPlugin::URL_OPTION) . '">';
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
        $urls = str_replace("http://" . $_SERVER['HTTP_HOST'], "", $urls);
        $urls = str_replace("https://" . $_SERVER['HTTP_HOST'], "", $urls);
        return $urls;
    }, 10, 1);

    // Initiliaze Hooks class which contains WordPress hook functions
    $hooks = new CachePurge\CloudFrontPlugin();
    $hooks->init();
}

