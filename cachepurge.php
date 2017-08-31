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

    $map = array(
        'press' => array(
            'template-news-room.php',
            'template-about.php'
        ),
        'case-studies-banner' => array(
            'template-case-studies-lp.php',
            'template-case-studies-v2.php',
            'template-case-studies-v3-filter.php',
            'template-case-studies-v3.php',
        ),
        'case-studies-ov' => array(
            'template-case-studies-lp.php',
            'template-case-studies-v2.php',
            'template-case-studies-v3-filter.php',
            'template-case-studies-v3.php',
        ),
        'mw-video' => array(
            'template-insights.php',
            'template-resources.php',
            'content-single-mw-video.php',
            'video-page-template.php',
        ),
        'insights' => array(
            'template-insights.php',
            'insight-topic-content.php',
            'template-blog-page-insights-backend.php',
            'content-single-post.php', // as it includes template-inc/template-blog-page-random-resource-topic.php
            'home.php', // as it includes template-inc/template-blog-page-random-resource-topic.php
        ),
        'mw-author' => array(
            'template-login.php',
            'template-login-5cols.php',
            'template-upcoming-events.php'
        ),
        'tip-and-trick' => array(
            'template-login.php',
            'template-login-5cols.php',
        ),
        'event' => array(
            'template-login-5cols.php',
            'template-login.php',
            'template-upcoming-events.php'
        ),
        'post' => array(
            'template-login-5cols.php',
            'template-login.php',
            'template-resources.php',
        ),
        'careers-people' => array(
            'template-new-careers.php',
        ),
        'layout' => array(
            'template-products.php',
            'template-product-v2.php'
        ),
        'product' => array(
            'template-products.php',
            'template-product-v2.php'
        )
    );

    if ($map[$post_type]) {
        $posts = new WP_Query(array(
            'meta_query' => array(
                array(
                    'key' => '_wp_page_template',
                    'value' => $map[$post_type],
                    'compare' => 'IN'
                )
            ),
            'post_type' => 'page',
            'posts_per_page' => -1,
        ));
        foreach ($posts->posts as $post) {
            $urls[] = get_permalink($post);
        }
    }
    return array_unique($urls);
}, 10, 2);


add_filter('cachepurge_urls', function ($urls) {
    $urls = str_replace('http://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    $urls = str_replace('https://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    return array_unique($urls);
}, 10, 1);

// Initiliaze Hooks class which contains WordPress hook functions
$hooks = new CachePurge\Plugin\Nginx();
$hooks->init();

