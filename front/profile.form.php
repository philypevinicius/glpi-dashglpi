<?php

/**
 * Plugin DashGLPI - Grava o direito "Dashboard Pro" de um perfil.
 *
 * Recebe o POST do checkbox em PluginDashglpiProfile::showForProfile() e
 * volta para a aba do perfil.
 *
 * NÃO chamar Session::checkCSRF($_POST) aqui: no GLPI 11 o CheckCsrfListener
 * já valida o _glpi_csrf_token no evento kernel.controller, ANTES de este
 * arquivo ser incluído - chamar de novo consumiria um token já validado e
 * devolveria "Acesso negado" com tudo correto (mesma armadilha documentada
 * em plugins/glpipulseui/front/printers.form.php).
 */
require_once __DIR__ . '/../../../inc/includes.php';

// Direito nativo "profile" (o de editar perfis do GLPI), não o direito novo
// do dashboard - é o mesmo controle de acesso que a tela de Configurar >
// Perfis já usa para qualquer outra aba de direitos.
Session::checkRight(Profile::$rightname, UPDATE);

$profilesId = (int) ($_POST['profiles_id'] ?? 0);
if ($profilesId <= 0) {
    Html::back();
    exit;
}

// Checkbox desmarcado não manda a chave no POST - ausência = 0 (sem
// acesso), não "não mexer": esta tela é o único jeito de desligar o
// direito de um perfil pela UI.
$valor = isset($_POST['plugin_dashglpi_dashboard']) ? READ : 0;

ProfileRight::updateProfileRights($profilesId, [
    PluginDashglpiDashboard::$rightname => $valor,
]);

Html::back();
