<?php

namespace CachePurge\Plugin;

use CachePurge\Api\CloudFlare as Api;

/**
 * Class CloudFlare
 * @package CachePurge\Plugin
 */
class CloudFlare extends Plugin
{

    const EMAIL_OPTION = 'cachepurge-cloudflare-email';
    const KEY_OPTION = 'cachepurge-cloudflare-key';
    const ZONE_ID_OPTION = 'cachepurge-cloudflare-zone-id';

    protected function isConfigured()
    {
        if (!get_option(Plugin::ENABLED_OPTION))
            return false;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $configured = get_option(CloudFlare::EMAIL_OPTION)
            && get_option(CloudFlare::KEY_OPTION);

        restore_current_blog();

        return $enabled && $configured;
    }

    /**
     * @return Api
     */
    protected function _getApi()
    {
        static $api;

        if ($api)
            return $api;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $api = new Api();
        $api->setEmail(get_option(CloudFlare::EMAIL_OPTION));
        $api->setKey(get_option(CloudFlare::KEY_OPTION));
        $api->setZoneId(get_option(CloudFlare::ZONE_ID_OPTION));

        restore_current_blog();

        return $api;
    }

    public function purgeEverything()
    {
        return $this->getApi()->invalidateEverything();
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

        $this->setup_override_settings();
    }
}
