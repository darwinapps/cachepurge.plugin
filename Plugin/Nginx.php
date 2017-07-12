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

        $current_blog_id = get_current_blog_id();
        if (!get_option(Nginx::OVERRIDE_OPTION))
            switch_to_blog(get_network()->site_id);

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $url = @parse_url(get_option(Nginx::URL_OPTION));

        switch_to_blog($current_blog_id);

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

        $current_blog_id = get_current_blog_id();
        if (!get_option(Nginx::OVERRIDE_OPTION))
            switch_to_blog(get_network()->site_id);

        $api = new Api();
        $api->setUrl(get_option(Nginx::URL_OPTION));
        $api->setUsername(get_option(Nginx::USERNAME_OPTION));
        $api->setPassword(get_option(Nginx::PASSWORD_OPTION));

        switch_to_blog($current_blog_id);

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

        if (get_current_blog_id() !== get_network()->site_id) {
            add_settings_field(
                Nginx::OVERRIDE_OPTION,
                'Override global settings',
                function () {
                    echo $this->override_checkbox_javascript(Nginx::OVERRIDE_OPTION);
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );
            register_setting(Plugin::SETTINGS_PAGE, Nginx::OVERRIDE_OPTION, 'intval');
        }
    }
}