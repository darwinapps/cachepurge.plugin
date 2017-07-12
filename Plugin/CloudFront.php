<?php

namespace CachePurge\Plugin;
use CachePurge\Api\CloudFront as Api;


class CloudFront extends Plugin
{

    const ACCESS_KEY_OPTION = 'cachepurge-cloudfront-access-key';
    const SECRET_KEY_OPTION = 'cachepurge-cloudfront-secret-key';
    const DISTRIBUTION_ID_OPTION = 'cachepurge-cloudfront-distribution-id';
    const OVERRIDE_OPTION = 'cachepurge-cloudfront-override';

    protected function isConfigured()
    {
        if (!get_option(Plugin::ENABLED_OPTION))
            return false;

        $current_blog_id = get_current_blog_id();

        if (!get_option(CloudFront::OVERRIDE_OPTION))
            switch_to_blog(BLOG_ID_CURRENT_SITE);

        $enabled = get_option(Plugin::ENABLED_OPTION);
        $configured = get_option(CloudFront::ACCESS_KEY_OPTION)
            && get_option(CloudFront::SECRET_KEY_OPTION)
            && get_option(CloudFront::DISTRIBUTION_ID_OPTION);

        switch_to_blog($current_blog_id);

        return $enabled && $configured;
    }

    protected function _getApi()
    {
        static $api;

        if ($api)
            return $api;

        $current_blog_id = get_current_blog_id();
        if (!get_option(CloudFront::OVERRIDE_OPTION))
            switch_to_blog(BLOG_ID_CURRENT_SITE);

        $api = new Api();
        $api->setAccessKey(get_option(CloudFront::ACCESS_KEY_OPTION));
        $api->setSecretKey(get_option(CloudFront::SECRET_KEY_OPTION));
        $api->setDistributionId(get_option(CloudFront::DISTRIBUTION_ID_OPTION));

        switch_to_blog($current_blog_id);

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

        if (get_current_blog_id() !== BLOG_ID_CURRENT_SITE) {
            add_settings_field(
                CloudFront::OVERRIDE_OPTION,
                'Override global settings',
                function () {
                    echo $this->override_checkbox_javascript(CloudFront::OVERRIDE_OPTION);
                },
                Plugin::SETTINGS_PAGE,
                Plugin::SETTINGS_SECTION
            );
            register_setting(Plugin::SETTINGS_PAGE, CloudFront::OVERRIDE_OPTION, 'intval');
        }
    }
}