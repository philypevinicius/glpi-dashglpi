<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;

/**
 * Plugin DashGLPI - Classe de queries do dashboard
 *
 * Todas as queries usam $DB->request() com array de critérios (GLPI 11 obrigatório).
 * Queries complexas usam QueryExpression, QueryFunction e QuerySubQuery.
 */
class PluginDashglpiDashboard
{
    /**
     * Direito dedicado só para VER o dashboard - separado de qualquer
     * direito de editar/pausar chamado que já exista no GLPI. Registrado em
     * plugin_dashglpi_install() (hook.php) e exibido/editável na aba
     * "Dashboard Pro" do Profile (ver PluginDashglpiProfile, registrada via
     * Plugin::registerClass() em setup.php).
     */
    public static $rightname = 'plugin_dashglpi_dashboard';

    /** Tem o direito de VER o dashboard? */
    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    /**
     * IDs de chamados atribuídos a pelo menos um dos técnicos informados
     * (glpi_tickets_users.type = 2, "atribuído a" - ver
     * CommonITILActor::ASSIGN). Resolvida uma vez por chamada e reaproveitada
     * em toda contagem/consulta do dashboard, em vez de repetir o JOIN em
     * cada query.
     *
     * @param array|null $technicianIds null = sem filtro (todos os chamados
     *                                  valem); [] = filtro manual sem nenhum
     *                                  técnico selecionado (nenhum chamado
     *                                  deve bater - ver ticketIdCriteria()).
     *
     * @return array|null null quando não há filtro; lista de IDs (pode ser
     *                     vazia) quando há.
     */
    private static function resolveTicketIdsForTechnicians($DB, ?array $technicianIds): ?array
    {
        if ($technicianIds === null) {
            return null;
        }
        if ($technicianIds === []) {
            return [];
        }

        $ids = [];
        $iterator = $DB->request([
            'SELECT'   => ['tickets_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_tickets_users',
            'WHERE'    => [
                'type'     => 2,
                'users_id' => array_map('intval', $technicianIds),
            ],
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['tickets_id'];
        }

        return $ids;
    }

    /**
     * Critério pronto para entrar num WHERE, restringindo pelos IDs de
     * chamado resolvidos acima. [] (sem efeito no WHERE) quando não há
     * filtro ativo.
     *
     * O `-1` no caso de filtro ativo SEM nenhum chamado batendo é de
     * propósito: um `WHERE id IN ()` vazio é inválido/indefinido em SQL, e
     * "nenhum id existe" precisa continuar significando ZERO resultados, não
     * "sem filtro" (que devolveria tudo).
     */
    private static function ticketIdCriteria(?array $ticketIds, string $column = 'id'): array
    {
        if ($ticketIds === null) {
            return [];
        }

        return [$column => $ticketIds === [] ? [-1] : $ticketIds];
    }

    /**
     * Percentual do prazo de SLA já consumido por um chamado, calculado a
     * partir das datas cruas - sem depender de nenhuma extensão/plugin de
     * SLA, só dos campos nativos glpi_tickets.date/time_to_resolve/solvedate.
     *
     * @param string|null $date          Criação do chamado (glpi_tickets.date)
     * @param string|null $timeToResolve Prazo de solução (glpi_tickets.time_to_resolve)
     * @param string|null $solveDate     Quando foi solucionado, null se ainda aberto
     * @param string|null $now           Instante de referência para chamado ainda
     *                                   aberto - injetável para teste; null usa o
     *                                   relógio real (time())
     *
     * @return float|null Percentual (pode passar de 100 = estourado); null
     *                     quando não há SLA aplicado (time_to_resolve vazio)
     *                     ou as datas não permitem calcular.
     */
    public static function calcularPercentualSla(
        ?string $date,
        ?string $timeToResolve,
        ?string $solveDate = null,
        ?string $now = null
    ): ?float {
        if (empty($date) || empty($timeToResolve)) {
            return null;
        }

        $inicio = strtotime($date);
        $prazo  = strtotime($timeToResolve);
        if ($inicio === false || $prazo === false || $prazo <= $inicio) {
            return null;
        }

        $referenciaBruta = $solveDate ?: $now;
        $referencia = $referenciaBruta !== null ? strtotime($referenciaBruta) : time();
        if ($referencia === false) {
            return null;
        }

        $total     = $prazo - $inicio;
        $decorrido = $referencia - $inicio;

        return round(($decorrido / $total) * 100, 1);
    }

    /**
     * Cor da barra de SLA a partir do percentual consumido.
     *
     * Faixas são uma escolha de design DESTE dashboard (não um valor nativo
     * do GLPI), pedidas pelo usuário na ordem azul → roxo conforme o prazo
     * se esgota:
     *   < 25%    azul     (tranquilo, acabou de abrir)
     *   25-50%   verde
     *   50-75%   amarelo
     *   75-90%   laranja
     *   90-100%  vermelho (próximo do limite)
     *   > 100%   roxo     (estourado/vencido)
     *
     * 'none' quando não há SLA aplicado - a UI não desenha barra nenhuma
     * nesse caso, em vez de uma barra colorida sem sentido.
     */
    public static function corSla(?float $percentual): string
    {
        if ($percentual === null) {
            return 'none';
        }
        if ($percentual > 100) {
            return 'purple';
        }
        if ($percentual >= 90) {
            return 'red';
        }
        if ($percentual >= 75) {
            return 'orange';
        }
        if ($percentual >= 50) {
            return 'yellow';
        }
        if ($percentual >= 25) {
            return 'green';
        }

        return 'blue';
    }

    /**
     * Retorna dados dos cards KPI + gráficos.
     *
     * @param array|null $technicianIds Filtro de equipe/técnicos ativo (ver
     *                                  ajax/dashboard.php) - null = sem
     *                                  filtro, todos os chamados contam.
     */
    public static function getDashboardData(?array $technicianIds = null): array
    {
        global $DB;

        $entityCriteria = getEntitiesRestrictCriteria('glpi_tickets');
        $ticketIds      = self::resolveTicketIdsForTechnicians($DB, $technicianIds);
        $idFiltro       = self::ticketIdCriteria($ticketIds, 'id');

        // --- Cards KPI ---
        $total       = self::countTickets($DB, $entityCriteria, [], $ticketIds);
        $abertos     = self::countTickets($DB, $entityCriteria, ['status' => 1], $ticketIds);
        $andamento   = self::countTickets($DB, $entityCriteria, ['status' => [2, 3]], $ticketIds);
        $pendentes   = self::countTickets($DB, $entityCriteria, ['status' => 4], $ticketIds);
        $finalizados = self::countTickets($DB, $entityCriteria, ['status' => [5, 6]], $ticketIds);

        // SLA vencido: não finalizados com time_to_resolve no passado
        $slaVencido = self::countTickets($DB, $entityCriteria, [
            'NOT' => ['status' => [5, 6]],
            ['NOT' => ['time_to_resolve' => null]],
            new QueryExpression('`glpi_tickets`.`time_to_resolve` < NOW()'),
        ], $ticketIds);

        $taxa = ($total > 0) ? round(($finalizados / $total) * 100) : 0;

        // Tempo médio de resolução (últimos 30 dias)
        $tempoMedioHoras = 0;
        $result = $DB->request([
            'SELECT' => [
                QueryFunction::avg(
                    QueryFunction::timestampdiff('SECOND', 'glpi_tickets.date', 'glpi_tickets.solvedate'),
                    'avg_sec'
                ),
            ],
            'FROM'  => 'glpi_tickets',
            'WHERE' => array_merge([
                'status'     => [5, 6],
                'is_deleted' => 0,
                new QueryExpression('`glpi_tickets`.`solvedate` >= DATE(NOW()) - INTERVAL 30 DAY'),
            ], $entityCriteria, $idFiltro),
        ]);
        $row = $result->current();
        if (!empty($row['avg_sec'])) {
            $tempoMedioHours = round($row['avg_sec'] / 3600);
            $tempoMedioHoras = $tempoMedioHours;
        }

        // Reabertos (heurística: não finalizados, criados há >3 dias, modificados recentemente)
        $reabertos = self::countTickets($DB, $entityCriteria, [
            'NOT' => ['status' => [5, 6]],
            new QueryExpression('`glpi_tickets`.`date` < DATE(NOW()) - INTERVAL 3 DAY'),
            new QueryExpression('`glpi_tickets`.`date_mod` >= DATE(NOW()) - INTERVAL 1 DAY'),
        ], $ticketIds);

        // --- Gráficos ---
        // Os gráficos também respeitam o filtro de equipe ativo, pelo mesmo
        // motivo dos KPIs: mostrar tendência/categorias de chamados que nem
        // aparecem na tabela filtrada abaixo seria inconsistente.

        // Fluxo de criação (últimos 30 dias)
        $trendData = [];
        $iterator = $DB->request([
            'SELECT' => [
                QueryFunction::dateFormat('glpi_tickets.date', '%d/%m', 'dia'),
                QueryFunction::count('glpi_tickets.id', false, 'total'),
            ],
            'FROM'  => 'glpi_tickets',
            'WHERE' => array_merge([
                'is_deleted' => 0,
                new QueryExpression('`glpi_tickets`.`date` >= DATE(NOW()) - INTERVAL 30 DAY'),
            ], $entityCriteria, $idFiltro),
            'GROUPBY' => [
                new QueryExpression('DATE(`glpi_tickets`.`date`)'),
            ],
            'ORDER' => [new QueryExpression('DATE(`glpi_tickets`.`date`) ASC')],
        ]);
        foreach ($iterator as $row) {
            $trendData[] = ['dia' => $row['dia'], 'total' => (int) $row['total']];
        }

        // Top 5 categorias
        $catData = [];
        $catEntityCriteria = getEntitiesRestrictCriteria('t');
        $iterator = $DB->request([
            'SELECT' => [
                'c.completename AS nome',
                QueryFunction::count('t.id', false, 'total'),
            ],
            'FROM'   => 'glpi_tickets AS t',
            'JOIN'   => [
                'glpi_itilcategories AS c' => [
                    'ON' => [
                        'c' => 'id',
                        't' => 'itilcategories_id',
                    ],
                ],
            ],
            'WHERE'  => array_merge([
                't.is_deleted' => 0,
            ], $catEntityCriteria, self::ticketIdCriteria($ticketIds, 't.id')),
            'GROUPBY' => ['c.id', 'c.completename'],
            'ORDER'   => ['total DESC'],
            'LIMIT'   => 5,
        ]);
        foreach ($iterator as $row) {
            $catData[] = ['nome' => $row['nome'], 'total' => (int) $row['total']];
        }

        // Abertos por mês (últimos 6 meses)
        $openedData = [];
        $iterator = $DB->request([
            'SELECT' => [
                QueryFunction::dateFormat('glpi_tickets.date', '%Y-%m', 'mes_ano'),
                QueryFunction::count('glpi_tickets.id', false, 'total'),
            ],
            'FROM'  => 'glpi_tickets',
            'WHERE' => array_merge([
                'is_deleted' => 0,
                new QueryExpression("`glpi_tickets`.`date` >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, '%Y-%m-01')"),
            ], $entityCriteria, $idFiltro),
            'GROUPBY' => [new QueryExpression("DATE_FORMAT(`glpi_tickets`.`date`, '%Y-%m')")],
            'ORDER'   => ['mes_ano ASC'],
        ]);
        foreach ($iterator as $row) {
            $openedData[] = ['mes_ano' => $row['mes_ano'], 'total' => (int) $row['total']];
        }

        // Solucionados por mês (últimos 6 meses)
        $solvedData = [];
        $iterator = $DB->request([
            'SELECT' => [
                QueryFunction::dateFormat('glpi_tickets.solvedate', '%Y-%m', 'mes_ano'),
                QueryFunction::count('glpi_tickets.id', false, 'total'),
            ],
            'FROM'  => 'glpi_tickets',
            'WHERE' => array_merge([
                'status'     => [5, 6],
                'is_deleted' => 0,
                new QueryExpression("`glpi_tickets`.`solvedate` >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, '%Y-%m-01')"),
            ], $entityCriteria, $idFiltro),
            'GROUPBY' => [new QueryExpression("DATE_FORMAT(`glpi_tickets`.`solvedate`, '%Y-%m')")],
            'ORDER'   => ['mes_ano ASC'],
        ]);
        foreach ($iterator as $row) {
            $solvedData[] = ['mes_ano' => $row['mes_ano'], 'total' => (int) $row['total']];
        }

        return [
            'cards_top' => [
                'total'       => (int) $total,
                'andamento'   => (int) $andamento,
                'taxa'        => $taxa,
                'sla'         => (int) $slaVencido,
                'tempo_medio' => $tempoMedioHoras,
                'reabertos'   => (int) $reabertos,
            ],
            'cards_bottom' => [
                'abertos'     => (int) $abertos,
                'atribuidos'  => (int) $andamento,
                'pendentes'   => (int) $pendentes,
                'finalizados' => (int) $finalizados,
            ],
            'charts' => [
                'trend_line'     => $trendData,
                'cat_bar'        => $catData,
                'monthly_opened' => $openedData,
                'monthly_solved' => $solvedData,
            ],
        ];
    }

    /**
     * Retorna ranking de técnicos do mês
     */
    public static function getRanking(): array
    {
        global $DB;

        $entityCriteria = getEntitiesRestrictCriteria('t');

        $iterator = $DB->request([
            'SELECT' => [
                new QueryExpression("TRIM(CONCAT(IFNULL(`u`.`firstname`, `u`.`name`), ' ', IFNULL(`u`.`realname`, ''))) AS `name`"),
                new QueryExpression("UPPER(CONCAT(LEFT(IFNULL(`u`.`firstname`, `u`.`name`), 1), LEFT(IFNULL(`u`.`realname`, ''), 1))) AS `avatar`"),
                QueryFunction::count('t.id', false, 'tickets'),
                new QueryExpression('COUNT(`t`.`id`) * 10 AS `points`'),
            ],
            'FROM'  => 'glpi_tickets AS t',
            'INNER JOIN' => [
                'glpi_tickets_users AS tu' => [
                    'ON' => [
                        'tu' => 'tickets_id',
                        't'  => 'id',
                    ],
                ],
                'glpi_users AS u' => [
                    'ON' => [
                        'u'  => 'id',
                        'tu' => 'users_id',
                    ],
                ],
            ],
            'WHERE' => array_merge([
                'tu.type'      => 2,
                't.status'     => [5, 6],
                't.is_deleted' => 0,
                ['NOT' => ['t.solvedate' => null]],
                new QueryExpression('MONTH(`t`.`solvedate`) = MONTH(CURRENT_DATE())'),
                new QueryExpression('YEAR(`t`.`solvedate`) = YEAR(CURRENT_DATE())'),
            ], $entityCriteria),
            'GROUPBY' => ['u.id', 'u.firstname', 'u.name', 'u.realname'],
            'ORDER'   => ['points DESC'],
            'LIMIT'   => 20,
        ]);

        $colors = ['#3b82f6', '#a855f7', '#22c55e', '#f59e0b', '#06b6d4'];
        $ranking = [];

        foreach ($iterator as $key => $row) {
            $item = [
                'name'    => $row['name'],
                'avatar'  => !empty($row['avatar']) ? $row['avatar'] : 'T',
                'tickets' => (int) $row['tickets'],
                'points'  => (int) $row['points'],
                'color'   => $colors[$key % count($colors)],
            ];
            $ranking[] = $item;
        }

        return $ranking;
    }

    /**
     * Retorna lista de tickets ativos
     */
    /**
     * Retorna lista de chamados ativos, com percentual/cor de SLA e
     * técnico(s) responsável(is) por chamado.
     *
     * @param array|null $technicianIds Filtro de equipe/técnicos ativo -
     *                                  null = sem filtro.
     */
    public static function getTicketsList(?array $technicianIds = null): array
    {
        global $DB;

        $entityCriteria = getEntitiesRestrictCriteria('t');
        $ticketIds      = self::resolveTicketIdsForTechnicians($DB, $technicianIds);

        $iterator = $DB->request([
            'SELECT' => [
                't.id', 't.name', 't.status', 't.date', 't.priority',
                't.time_to_resolve', 't.solvedate',
                new QueryExpression("IFNULL(`c`.`completename`, 'Sem Categoria') AS `category`"),
                new QueryExpression("IFNULL(`u`.`name`, 'N/A') AS `user_name`"),
            ],
            'FROM' => 'glpi_tickets AS t',
            'LEFT JOIN' => [
                'glpi_itilcategories AS c' => [
                    'ON' => [
                        'c' => 'id',
                        't' => 'itilcategories_id',
                    ],
                ],
                'glpi_users AS u' => [
                    'ON' => [
                        'u' => 'id',
                        't' => 'users_id_recipient',
                    ],
                ],
            ],
            'WHERE' => array_merge([
                't.status'     => [1, 2, 3, 4],
                't.is_deleted' => 0,
            ], $entityCriteria, self::ticketIdCriteria($ticketIds, 't.id')),
            'ORDER' => ['t.priority DESC', 't.date DESC'],
            'LIMIT' => 100,
        ]);

        $tickets = [];
        $ids     = [];
        foreach ($iterator as $row) {
            $id        = (int) $row['id'];
            $ids[]     = $id;
            $percentual = self::calcularPercentualSla($row['date'], $row['time_to_resolve'], $row['solvedate']);

            $row['sla_percentual'] = $percentual;
            $row['sla_cor']        = self::corSla($percentual);
            // time_to_resolve/solvedate continuam na linha, de propósito: é
            // o prazo real que o Monitor de SLA usa no contador regressivo
            // no front (antes, dados 100% inventados - ver script.js).

            $tickets[$id] = $row;
        }

        if ($ids === []) {
            return [];
        }

        // Técnico(s) responsável(is) - bulk query (mesmo padrão de
        // getAssetsList() logo abaixo), em vez de JOIN direto na consulta
        // principal: um chamado pode ter mais de um técnico atribuído, e um
        // JOIN duplicaria a linha do chamado por técnico extra.
        $tecnicosPorChamado = [];
        $tecIterator = $DB->request([
            'SELECT'     => ['tu.tickets_id', 'u.id AS users_id', 'u.name', 'u.firstname', 'u.realname'],
            'FROM'       => 'glpi_tickets_users AS tu',
            'INNER JOIN' => [
                'glpi_users AS u' => [
                    'ON' => ['u' => 'id', 'tu' => 'users_id'],
                ],
            ],
            'WHERE' => [
                'tu.tickets_id' => $ids,
                'tu.type'       => 2,
            ],
            'ORDER' => ['u.name ASC'],
        ]);
        foreach ($tecIterator as $row) {
            $nome = trim((string) ($row['firstname'] ?: $row['name']) . ' ' . (string) $row['realname']);
            $tecnicosPorChamado[(int) $row['tickets_id']][] = $nome !== '' ? $nome : $row['name'];
        }

        foreach ($tickets as $id => &$ticket) {
            $tecnicos             = $tecnicosPorChamado[$id] ?? [];
            $ticket['tecnicos']   = $tecnicos;
            $ticket['tecnico']    = $tecnicos !== [] ? implode(', ', $tecnicos) : 'Não atribuído';
        }
        unset($ticket);

        return array_values($tickets);
    }

    /** "Nome Sobrenome" a partir das colunas nativas de glpi_users, com fallback pro login. */
    private static function formatUserName(array $row): string
    {
        $nome = trim((string) ($row['firstname'] ?: $row['name']) . ' ' . (string) $row['realname']);

        return $nome !== '' ? $nome : (string) $row['name'];
    }

    /**
     * O usuário é gestor (glpi_groups_users.is_manager = 1) de pelo menos
     * um Grupo do GLPI? É o que decide se o botão "Minha equipe" aparece -
     * checado à parte de getMyTeam() porque a pergunta é "é gestor de
     * grupo", não "a equipe tem gente" (as duas quase sempre andam juntas,
     * mas são perguntas diferentes).
     */
    public static function isGroupManager(int $usersId): bool
    {
        global $DB;

        if ($usersId <= 0) {
            return false;
        }

        $iterator = $DB->request([
            'COUNT'  => 'cpt',
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => [
                'users_id'   => $usersId,
                'is_manager' => 1,
            ],
        ]);
        $row = $iterator->current();

        return (int) ($row['cpt'] ?? 0) > 0;
    }

    /**
     * "Minha equipe": todos os usuários que são membros de algum Grupo do
     * GLPI em que o usuário informado é GESTOR (glpi_groups_users.is_manager
     * = 1). Pura leitura da relação nativa de Grupos - nenhuma tabela nova.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    public static function getMyTeam(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'     => [
                'u.id', 'u.name', 'u.firstname', 'u.realname',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_groups_users AS gestor',
            'INNER JOIN' => [
                // Grupos em que o usuário é gestor.
                'glpi_groups AS g' => [
                    'ON' => ['g' => 'id', 'gestor' => 'groups_id'],
                ],
                // Membros desses MESMOS grupos.
                'glpi_groups_users AS membro' => [
                    'ON' => ['membro' => 'groups_id', 'gestor' => 'groups_id'],
                ],
                'glpi_users AS u' => [
                    'ON' => ['u' => 'id', 'membro' => 'users_id'],
                ],
            ],
            'WHERE' => [
                'gestor.users_id'   => $usersId,
                'gestor.is_manager' => 1,
                'u.is_active'       => 1,
                'u.is_deleted'      => 0,
            ],
            'ORDER' => ['u.name ASC'],
        ]);

        $equipe = [];
        foreach ($iterator as $row) {
            $equipe[(int) $row['id']] = [
                'id'   => (int) $row['id'],
                'nome' => self::formatUserName($row),
            ];
        }

        return array_values($equipe);
    }

    /**
     * Técnicos com pelo menos um chamado em aberto atribuído (status 1-4),
     * independente de fazerem parte de alguma equipe/grupo. Usado para
     * compor a lista do painel de seleção manual junto com getMyTeam(): um
     * técnico "avulso" (fora da equipe) que está com chamado aberto precisa
     * poder ser selecionado, mesmo sem gestor nenhum o gerenciando.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    public static function getTechniciansWithOpenTickets(): array
    {
        global $DB;

        $entityCriteria = getEntitiesRestrictCriteria('t');

        $iterator = $DB->request([
            'SELECT'     => ['u.id', 'u.name', 'u.firstname', 'u.realname'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_tickets_users AS tu',
            'INNER JOIN' => [
                'glpi_tickets AS t' => [
                    'ON' => ['t' => 'id', 'tu' => 'tickets_id'],
                ],
                'glpi_users AS u' => [
                    'ON' => ['u' => 'id', 'tu' => 'users_id'],
                ],
            ],
            'WHERE' => array_merge([
                'tu.type'      => 2,
                't.status'     => [1, 2, 3, 4],
                't.is_deleted' => 0,
            ], $entityCriteria),
            'ORDER' => ['u.name ASC'],
        ]);

        $tecnicos = [];
        foreach ($iterator as $row) {
            $tecnicos[(int) $row['id']] = [
                'id'   => (int) $row['id'],
                'nome' => self::formatUserName($row),
            ];
        }

        return array_values($tecnicos);
    }

    /**
     * União de getMyTeam() com getTechniciansWithOpenTickets(), deduplicada
     * e ordenada por nome - é a lista de opções do painel "Escolher
     * técnicos...": minha equipe inteira, mesmo quem não tem chamado agora,
     * mais qualquer técnico fora dela que esteja com chamado aberto.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    public static function getFilterableTechnicians(int $usersId): array
    {
        $porId = [];
        foreach (array_merge(self::getMyTeam($usersId), self::getTechniciansWithOpenTickets()) as $tecnico) {
            $porId[$tecnico['id']] = $tecnico;
        }

        usort($porId, static fn(array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));

        return array_values($porId);
    }

    /**
     * Retorna lista de computadores/ativos
     */
    public static function getAssetsList(): array
    {
        global $DB;

        $entityCriteria = getEntitiesRestrictCriteria('c');

        $iterator = $DB->request([
            'SELECT' => [
                'c.id', 'c.name', 'c.serial',
                'loc.completename AS location',
                'cm.name AS model',
                'man.name AS manufacturer',
                'st.completename AS status',
            ],
            'FROM' => 'glpi_computers AS c',
            'LEFT JOIN' => [
                'glpi_locations AS loc' => [
                    'ON' => [
                        'loc' => 'id',
                        'c'   => 'locations_id',
                    ],
                ],
                'glpi_computermodels AS cm' => [
                    'ON' => [
                        'cm' => 'id',
                        'c'  => 'computermodels_id',
                    ],
                ],
                'glpi_manufacturers AS man' => [
                    'ON' => [
                        'man' => 'id',
                        'c'   => 'manufacturers_id',
                    ],
                ],
                'glpi_states AS st' => [
                    'ON' => [
                        'st' => 'id',
                        'c'  => 'states_id',
                    ],
                ],
            ],
            'WHERE' => array_merge([
                'c.is_deleted'  => 0,
                'c.is_template' => 0,
            ], $entityCriteria),
            'ORDER' => ['c.name ASC'],
            'LIMIT' => 100,
        ]);

        // Coletar IDs e dados base
        $assets = [];
        $ids    = [];
        foreach ($iterator as $row) {
            $ids[]                    = (int) $row['id'];
            $assets[(int) $row['id']] = $row;
        }

        if (empty($ids)) {
            return [];
        }

        // Bulk: OS
        $osMap      = [];
        $osIterator = $DB->request([
            'SELECT'    => ['ios.items_id', 'os.name'],
            'FROM'      => 'glpi_items_operatingsystems AS ios',
            'LEFT JOIN' => [
                'glpi_operatingsystems AS os' => [
                    'ON' => ['os' => 'id', 'ios' => 'operatingsystems_id'],
                ],
            ],
            'WHERE' => [
                'ios.items_id'   => $ids,
                'ios.itemtype'   => 'Computer',
                'ios.is_deleted' => 0,
            ],
        ]);
        foreach ($osIterator as $row) {
            $itemId = (int) $row['items_id'];
            if (!empty($row['name'])) {
                $osMap[$itemId][] = $row['name'];
            }
        }

        // Bulk: CPU
        $cpuMap      = [];
        $cpuIterator = $DB->request([
            'SELECT'     => ['idp.items_id', 'dp.designation'],
            'FROM'       => 'glpi_items_deviceprocessors AS idp',
            'INNER JOIN' => [
                'glpi_deviceprocessors AS dp' => [
                    'ON' => ['dp' => 'id', 'idp' => 'deviceprocessors_id'],
                ],
            ],
            'WHERE' => [
                'idp.items_id' => $ids,
                'idp.itemtype' => 'Computer',
            ],
        ]);
        foreach ($cpuIterator as $row) {
            $itemId = (int) $row['items_id'];
            if (!isset($cpuMap[$itemId]) && !empty($row['designation'])) {
                $cpuMap[$itemId] = $row['designation'];
            }
        }

        // Bulk: RAM
        $ramMap      = [];
        $ramIterator = $DB->request([
            'SELECT'  => [
                'items_id',
                QueryFunction::sum('size', false, 'total'),
            ],
            'FROM'    => 'glpi_items_devicememories',
            'WHERE'   => [
                'items_id'   => $ids,
                'itemtype'   => 'Computer',
                'is_deleted' => 0,
            ],
            'GROUPBY' => ['items_id'],
        ]);
        foreach ($ramIterator as $row) {
            $ramMap[(int) $row['items_id']] = (int) ($row['total'] ?? 0);
        }

        // Bulk: Disk
        $diskMap      = [];
        $diskIterator = $DB->request([
            'SELECT'  => [
                'items_id',
                QueryFunction::sum('totalsize', false, 'disk_total'),
                QueryFunction::sum('freesize', false, 'disk_free'),
            ],
            'FROM'    => 'glpi_items_disks',
            'WHERE'   => [
                'items_id'   => $ids,
                'itemtype'   => 'Computer',
                'is_deleted' => 0,
            ],
            'GROUPBY' => ['items_id'],
        ]);
        foreach ($diskIterator as $row) {
            $diskMap[(int) $row['items_id']] = [
                'total' => (int) ($row['disk_total'] ?? 0),
                'free'  => (int) ($row['disk_free'] ?? 0),
            ];
        }

        // Montar resultado final
        $result = [];
        foreach ($assets as $id => $asset) {
            $osNames             = $osMap[$id] ?? [];
            $asset['os_name']    = implode(', ', array_unique($osNames));
            $asset['cpu']        = $cpuMap[$id] ?? '';
            $asset['ram_total']  = $ramMap[$id] ?? 0;
            $asset['disk_total'] = $diskMap[$id]['total'] ?? 0;
            $asset['disk_free']  = $diskMap[$id]['free'] ?? 0;
            $result[] = $asset;
        }

        return $result;
    }

    // ==================== Helpers ====================

    /**
     * Contagem de tickets com filtros adicionais.
     *
     * @param array|null $ticketIds Filtro de equipe/técnicos já resolvido
     *                              (ver resolveTicketIdsForTechnicians()) -
     *                              null = sem filtro.
     */
    private static function countTickets($DB, array $entityCriteria, array $extraWhere, ?array $ticketIds = null): int
    {
        $where = array_merge(
            ['is_deleted' => 0],
            $extraWhere,
            $entityCriteria,
            self::ticketIdCriteria($ticketIds, 'id')
        );

        $result = $DB->request([
            'COUNT'  => 'cpt',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => $where,
        ]);
        $row = $result->current();
        return (int) ($row['cpt'] ?? 0);
    }

}
