<?php

namespace CachePurge\Plugin;
use CachePurge\Api\CloudFlare as Api;

/**
 * Class CloudFlarePlugin
 * @package CachePurge\Plugin
 */
class CloudFlare extends Plugin
{

    const EMAIL_OPTION = 'cachepurge-cloudflare-email';
    const KEY_OPTION = 'cachepurge-cloudflare-key';
    const ZONE_ID_OPTION = 'cachepurge-cloudflare-zone-id';
    const OVERRIDE_OPTION = 'cachepurge-cloudflare-override';

    protected function isConfigured()
    {
        if (!get_option(Plugin::ENABLED_OPTION))
            return false;

        $current_blog_id = get_current_blog_id();

        if (!get_option(CloudFlarePlugin::OVERRIDE_OPTION))
            switch_to_blog(BLOG_ID_CURRENT_SITE);

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $configured = get_option(CloudFlarePlugin::EMAIL_OPTION)
            && get_option(CloudFlarePlugin::KEY_OPTION);

        switch_to_blog($current_blog_id);

        return $enabled && $configured;
    }

    protected function _getApi()
    {
        static $api;

        if ($api)
            return $api;

        $current_blog_id = get_current_blog_id();
        if (!get_option(CloudFlare::OVERRIDE_OPTION))
            switch_to_blog(BLOG_ID_CURRENT_SITE);

        $api = new Api();
        $api->setEmail(get_option(CloudFlare::EMAIL_OPTION));
        $api->setKey(get_option(CloudFlare::KEY_OPTION));
        $api->setZoneId(get_option(CloudFlare::ZONE_ID_OPTION));

        switch_to_blog($current_blog_id);

        return $api;
    }

    public function setup_settings()
    {
        parent::setup_settings();

        add_settings_field(
            CloudFlare::EMAIL_OPTION,
            'Email',
            function () {
                echo '<input name="' . CloudFlare::EMAIL_OPTION . '" value="' . get_option(CloudFlare::EMAIL_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            CloudFlare::KEY_OPTION,
            'Key',
            function () {
                echo '<input name="' . CloudFlare::KEY_OPTION . '" value="' . get_option(CloudFlare::KEY_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            CloudFlare::ZONE_ID_OPTION,
            'Zone Id',
            function () {
                echo '<input name="' . CloudFlare::ZONE_ID_OPTION . '" value="' . get_option(CloudFlare::ZONE_ID_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        register_setting(Plugin::SETTINGS_PAGE, CloudFlare::EMAIL_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, CloudFlare::KEY_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, CloudFlare::ZONE_ID_OPTION, 'wp_filter_nohtml_kses');

        if (get_current_blog_id() !== BLOG_ID_CURRENT_SITE) {
            add_settings_field(
                CloudFlare::OVERRIDE_OPTION,
                'Override global settings',
                function () {
                    echo $this->override_checkbox_javascript(CloudFlare::OVERRIDE_OPTION);
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );
            register_setting(Plugin::SETTINGS_PAGE, CloudFlare::OVERRIDE_OPTION, 'intval');
        }
    }
}
