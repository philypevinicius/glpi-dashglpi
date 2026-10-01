<?php

define('PLUGIN_DASHGLPI_VERSION', '1.0.0');
define('PLUGIN_DASHGLPI_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_DASHGLPI_MAX_GLPI_VERSION', '11.0.99');

function plugin_version_dashglpi()
{
    return [
        'name'         => 'Dashboard GLPI Pro',
        'version'      => PLUGIN_DASHGLPI_VERSION,
        'author'       => 'Diogo Berlanda',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DASHGLPI_MIN_GLPI_VERSION,
                'max' => PLUGIN_DASHGLPI_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_init_dashglpi()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['dashglpi'] = true;

    // Único JS carregado GLOBALMENTE (todas as páginas do GLPI): força o
    // link do menu a abrir em nova aba, já que o dashboard é uma página
    // standalone com seu próprio HTML (sem header/footer do GLPI) - ver
    // public/js/menu.js. Nenhum CSS é injetado globalmente, de propósito:
    // o tema cyberpunk/glassmorphism do dashboard não deve vazar para o
    // resto do GLPI.
    $PLUGIN_HOOKS['add_javascript']['dashglpi'] = 'public/js/menu.js';

    // Registra PluginDashglpiProfile como uma aba do item Profile - é o que
    // faz o direito "Dashboard Pro" aparecer em Configurar > Perfis >
    // [perfil], junto dos outros direitos do GLPI. Ver
    // inc/profile.class.php.
    //
    // O item de MENU em si (link "Dashboard Pro" na barra lateral) continua
    // registrado do jeito que já estava, via redefine_menus em hook.php -
    // não duplicado aqui. Esta chamada cuida só da aba de direitos.
    Plugin::registerClass('PluginDashglpiProfile', ['addtabon' => ['Profile']]);
}

function plugin_dashglpi_check_prerequisites()
{
    return true;
}

function plugin_dashglpi_check_config()
{
    return true;
}
