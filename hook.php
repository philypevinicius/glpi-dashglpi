<?php

/**
 * Plugin DashGLPI - Hook functions
 */

/**
 * Instalação do plugin.
 *
 * Não cria tabela nenhuma - o dashboard só LÊ dados nativos do GLPI
 * ($DB->request() em inc/dashboard.class.php). A única coisa que a
 * instalação precisa registrar é o direito de acesso ao dashboard
 * (ver PluginDashglpiDashboard::$rightname): ProfileRight::addProfileRights()
 * cria a linha do direito para TODOS os perfis existentes, com valor 0 (sem
 * acesso) por padrão - é o admin quem liga manualmente para o(s) perfil(is)
 * que devem enxergar o dashboard, em Configurar > Perfis > [perfil] > aba
 * "Dashboard Pro".
 */
function plugin_dashglpi_install()
{
    ProfileRight::addProfileRights([PluginDashglpiDashboard::$rightname]);

    return true;
}

/**
 * Desinstalação do plugin.
 *
 * Remove o direito de TODOS os perfis - sem isso, a linha ficaria órfã em
 * glpi_profilerights (referenciando um direito que não existe mais).
 */
function plugin_dashglpi_uninstall()
{
    ProfileRight::deleteProfileRights([PluginDashglpiDashboard::$rightname]);

    return true;
}

/**
 * Adiciona o Dashboard no menu de navegação do GLPI.
 *
 * Só aparece para quem tem o direito "Dashboard Pro" (ver
 * PluginDashglpiDashboard::$rightname) - a checagem aqui é sobre o LINK do
 * menu; front/dashboard.php faz a sua PRÓPRIA checagem (Session::checkRight)
 * antes de renderizar qualquer coisa, porque um usuário sem o direito
 * digitando a URL direto não passa por este hook nenhuma vez.
 */
function plugin_dashglpi_redefine_menus($menus)
{
    global $CFG_GLPI;

    if (Session::getLoginUserID() && Session::haveRight(PluginDashglpiDashboard::$rightname, READ)) {
        $menus['helpdesk']['content']['dashglpi'] = [
            'title' => __('Dashboard Pro', 'dashglpi'),
            'page'  => $CFG_GLPI['root_doc'] . '/plugins/dashglpi/front/dashboard.php',
            'icon'  => 'ti ti-chart-dots',
        ];
    }

    return $menus;
}
