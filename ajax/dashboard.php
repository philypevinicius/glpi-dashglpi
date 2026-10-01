<?php

/**
 * Plugin DashGLPI - Endpoint AJAX
 *
 * Recebe ?action=<nome> e retorna JSON.
 * Requer sessão GLPI ativa (usuário logado) E o direito de VER o dashboard
 * (PluginDashglpiDashboard::canView()) - sem o direito, o link do menu nem
 * aparece (ver hook.php), mas alguém pode digitar a URL do AJAX direto, e
 * esse acesso teria que ser barrado aqui também.
 *
 * Filtro de equipe/técnicos (?equipe=todos|minha|manual&tecnicos=1,2,3):
 * resolvido uma vez no topo e passado para as ações que exibem chamados
 * (dashboard_data, tickets_list) - ver resolverFiltroTecnicos() abaixo.
 */

// Carrega o GLPI
require_once __DIR__ . '/../../../inc/includes.php';

// Carrega as classes do plugin
require_once __DIR__ . '/../inc/dashboard.class.php';

// Verifica se o usuário está logado
Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

if (!PluginDashglpiDashboard::canView()) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

/**
 * Lê ?equipe=todos|minha|manual (+ ?tecnicos=1,2,3 quando manual) e devolve
 * a lista de IDs de técnico a filtrar, ou null quando não há filtro
 * ("todos", o padrão quando o parâmetro está ausente).
 *
 * "manual" com ?tecnicos= vazio devolve [] de propósito - filtro ativo, zero
 * técnicos selecionados, é diferente de "sem filtro" (que devolveria null).
 */
function dashglpi_resolver_filtro_tecnicos(): ?array
{
    $equipe = $_GET['equipe'] ?? 'todos';

    switch ($equipe) {
        case 'minha':
            $usersId = Session::getLoginUserID();
            return array_column(PluginDashglpiDashboard::getMyTeam((int) $usersId), 'id');

        case 'manual':
            $bruto = trim((string) ($_GET['tecnicos'] ?? ''));
            if ($bruto === '') {
                return [];
            }
            $ids = array_map('intval', explode(',', $bruto));
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
            return $ids;

        case 'todos':
        default:
            return null;
    }
}

$action           = $_GET['action'] ?? '';
$filtroTecnicos    = dashglpi_resolver_filtro_tecnicos();

try {
    switch ($action) {
        case 'dashboard_data':
            $data = PluginDashglpiDashboard::getDashboardData($filtroTecnicos);
            echo json_encode($data);
            break;

        case 'get_ranking':
            echo json_encode(PluginDashglpiDashboard::getRanking());
            break;

        case 'tickets_list':
            echo json_encode(PluginDashglpiDashboard::getTicketsList($filtroTecnicos));
            break;

        case 'assets_list':
            echo json_encode(PluginDashglpiDashboard::getAssetsList());
            break;

        case 'filter_options':
            $usersId = (int) Session::getLoginUserID();
            echo json_encode([
                'minha_equipe' => [
                    'disponivel' => PluginDashglpiDashboard::isGroupManager($usersId),
                    'tecnicos'   => PluginDashglpiDashboard::getMyTeam($usersId),
                ],
                'todos_tecnicos' => PluginDashglpiDashboard::getFilterableTechnicians($usersId),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Ação inválida.']);
            break;
    }
} catch (\Throwable $e) {
    $errorMsg = $e->getMessage() . " (Line: " . $e->getLine() . ", File: " . basename($e->getFile()) . ")";
    error_log("[DashGLPI] AJAX error: " . $errorMsg);
    error_log("[DashGLPI] Stack: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor.']);
}
