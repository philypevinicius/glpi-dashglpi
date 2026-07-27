<?php

define('PLUGIN_GLPIPULSETHEME_VERSION', '1.0.0');
define('PLUGIN_GLPIPULSETHEME_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_GLPIPULSETHEME_MAX_GLPI_VERSION', '11.0.99');

function plugin_version_glpipulsetheme()
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

function plugin_init_glpipulsetheme()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpipulsetheme'] = true;

    $PLUGIN_HOOKS['add_css']['glpipulsetheme'] =
        'public/css/pulse-theme.css';

    $PLUGIN_HOOKS['add_javascript']['glpipulsetheme'] =
        'public/js/pulse-theme.js';
}

function plugin_glpipulsetheme_check_prerequisites()
{
    return true;
}

function plugin_glpipulsetheme_check_config()
{
    return true;
}