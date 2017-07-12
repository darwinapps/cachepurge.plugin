<?php

namespace CachePurge\Plugin;
use CachePurge\Api\CloudFront as Api;


class CloudFront extends Plugin
{

    const ACCESS_KEY_OPTION = 'cachepurge-cloudfront-access-key';
    const SECRET_KEY_OPTION = 'cachepurge-cloudfront-secret-key';
    const DISTRIBUTION_ID_OPTION = 'cachepurge-cloudfront-distribution-id';

    protected function isConfigured()
    {
        if (!get_option(Plugin::ENABLED_OPTION))
            return false;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $configured = get_option(CloudFront::ACCESS_KEY_OPTION)
            && get_option(CloudFront::SECRET_KEY_OPTION)
            && get_option(CloudFront::DISTRIBUTION_ID_OPTION);

        $this->restore_current_blog();

        return $enabled && $configured;
    }

    protected function _getApi()
    {
        static $api;

        if ($api)
            return $api;

        if (!get_option(Plugin::OVERRIDE_OPTION))
            $this->switch_to_main_blog();

        $api = new Api();
        $api->setAccessKey(get_option(CloudFront::ACCESS_KEY_OPTION));
        $api->setSecretKey(get_option(CloudFront::SECRET_KEY_OPTION));
        $api->setDistributionId(get_option(CloudFront::DISTRIBUTION_ID_OPTION));

        $this->restore_current_blog();

        return $api;
    }

    public function setup_settings()
    {
        parent::setup_settings();

        add_settings_field(
            CloudFront::ACCESS_KEY_OPTION,
            'Access Key',
            function () {
                echo '<input name="' . CloudFront::ACCESS_KEY_OPTION . '" value="' . get_option(CloudFront::ACCESS_KEY_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            CloudFront::SECRET_KEY_OPTION,
            'Secret Key',
            function () {
                echo '<input name="' . CloudFront::SECRET_KEY_OPTION . '" value="' . get_option(CloudFront::SECRET_KEY_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        add_settings_field(
            CloudFront::DISTRIBUTION_ID_OPTION,
            'Distribution Id',
            function () {
                echo '<input name="' . CloudFront::DISTRIBUTION_ID_OPTION . '" value="' . get_option(CloudFront::DISTRIBUTION_ID_OPTION) . '">';
            },
            Plugin::SETTINGS_PAGE,
            Plugin::SETTINGS_SECTION
        );

        register_setting(Plugin::SETTINGS_PAGE, CloudFront::ACCESS_KEY_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, CloudFront::SECRET_KEY_OPTION, 'wp_filter_nohtml_kses');
        register_setting(Plugin::SETTINGS_PAGE, CloudFront::DISTRIBUTION_ID_OPTION, 'wp_filter_nohtml_kses');

        $this->setup_override_settings();
    }
}