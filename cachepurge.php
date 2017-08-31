<?php
/**
 * Plugin Name: Cache Purge
 * Plugin URI: https://wordpress.org/plugins/darwin-cachepurge/
 * Plugin Author: Aleksandr Guidrevitch
 * Version: 0.0.2
 * Author: DarwinApps, Aleksandr Guidrevitch
 * Author URI: http://drwn.co/
 * Description: Selective cache purge on publish
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('CACHEPURGE_DRYRUN', 1);

require(__DIR__ . '/Api/Api.php');
require(__DIR__ . '/Api/CloudFlare.php');
require(__DIR__ . '/Api/CloudFront.php');
require(__DIR__ . '/Api/Nginx.php');
require(__DIR__ . '/Plugin/Plugin.php');
require(__DIR__ . '/Plugin/CloudFlare.php');
require(__DIR__ . '/Plugin/CloudFront.php');
require(__DIR__ . '/Plugin/Nginx.php');

// not needed for CloudFlare, they rely on full urls,
// depends on NGINX selective cache purge setup
add_filter('cachepurge_post_related_links', function ($urls, $postId) {
    $post_type = get_post_type($postId);

    if ($post_type == 'press') {
        $posts = get_posts( array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'template-news-room.php',
            'post_type' => 'page',
            'posts_per_page' => 9999,
        ) );
        foreach ($posts as $post) {
            $urls[] = get_permalink($post);
        }
    }
    return $urls;
}, 10, 2);


add_filter('cachepurge_urls', function ($urls) {
    $urls = str_replace('http://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    $urls = str_replace('https://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    return array_unique($urls);
}, 10, 1);

// Initiliaze Hooks class which contains WordPress hook functions
$hooks = new CachePurge\Plugin\Nginx();
$hooks->init();

