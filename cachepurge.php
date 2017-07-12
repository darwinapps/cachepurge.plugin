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

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

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
add_filter('cachepurge_urls', function ($urls) {
    $urls = str_replace('http://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    $urls = str_replace('https://' . $_SERVER['HTTP_HOST'] . '/', '/', $urls);
    return array_unique($urls);
}, 10, 1);

// Initiliaze Hooks class which contains WordPress hook functions
$hooks = new CachePurge\Plugin\Nginx();
$hooks->init();

