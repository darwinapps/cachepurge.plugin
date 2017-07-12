<?php

namespace CachePurge\Plugin;

use CachePurge\Api\Nginx as Api;

class Nginx extends Plugin
{
    const URL_OPTION = 'cachepurge-nginx-url';
    const USERNAME_OPTION = 'cachepurge-nginx-username';
    const PASSWORD_OPTION = 'cachepurge-nginx-password';
    const OVERRIDE_OPTION = 'cachepurge-nginx-override';

    protected function isConfigured()
    {
        if (!get_option(Plugin::ENABLED_OPTION))
            return false;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $url = @parse_url(get_option(Nginx::URL_OPTION));

        restore_current_blog();

        if (!$enabled)
            return false;

        return $url
            ? isset($url['scheme']) && isset($url['host']) && isset($url['path'])
            : false;

    }

    protected function _getApi()
    {
        static $api;

        if ($api)
            return $api;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $api = new Api();
        $api->setUrl(get_option(Nginx::URL_OPTION));
        $api->setUsername(get_option(Nginx::USERNAME_OPTION));
        $api->setPassword(get_option(Nginx::PASSWORD_OPTION));

        restore_current_blog();

        return $api;
    }

    public function setup_settings()
    {
        parent::setup_settings();

        add_settings_field(
            Nginx::URL_OPTION,
            'URL',
            function () {
                echo '<input name="' . Nginx::URL_OPTION . '" value="' . get_option(Nginx::URL_OPTION) . '" size="100">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            Nginx::USERNAME_OPTION,
            'Username',
            function () {
                echo '<input name="' . Nginx::USERNAME_OPTION . '" value="' . get_option(Nginx::USERNAME_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            Nginx::PASSWORD_OPTION,
            'Password',
            function () {
                echo '<input name="' . Nginx::PASSWORD_OPTION . '" value="' . get_option(Nginx::PASSWORD_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        register_setting(Plugin::SETTINGS_PAGE, Nginx::URL_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, Nginx::USERNAME_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, Nginx::PASSWORD_OPTION, 'wp_filter_nohtml_kses');

        $this->setup_override_settings();
    }
}