<?php

define('PLUGIN_GLPIPULSETHEME_VERSION', '1.0.0');
define('PLUGIN_GLPIPULSETHEME_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_GLPIPULSETHEME_MAX_GLPI_VERSION', '11.0.99');

function plugin_version_dashglpi()
{
    return [
        'name'         => 'GLPI Pulse Theme',
        'version'      => PLUGIN_GLPIPULSETHEME_VERSION,
        'author'       => 'Philype',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GLPIPULSETHEME_MIN_GLPI_VERSION,
                'max' => PLUGIN_GLPIPULSETHEME_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_init_dashglpi()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['dashglpi'] = true;

    $PLUGIN_HOOKS['add_css']['dashglpi'] =
        'public/css/pulse-theme.css';

    $PLUGIN_HOOKS['add_javascript']['dashglpi'] =
        'public/js/pulse-theme.js';
}

function plugin_dashglpi_check_prerequisites()
{
    return true;
}

function plugin_dashglpi_check_config()
{
    return true;
}