<?php

/**
 * Plugin DashGLPI - Aba de direitos no item Profile.
 *
 * Classe "provedora de aba" pura (sem tabela própria no banco) - registrada
 * em plugin_init_dashglpi() via Plugin::registerClass(['addtabon' =>
 * ['Profile']]), que por sua vez chama CommonGLPI::registerStandardTab().
 * É o mecanismo padrão do GLPI para um plugin acrescentar uma aba a um item
 * já existente do núcleo: só getTabNameForItem() (instância) e
 * displayTabContentForItem() (estático) precisam existir.
 *
 * O objetivo, pedido pelo usuário: em Configurar > Perfis > [perfil], uma
 * aba "Dashboard Pro" com um único checkbox "Ver" - marcado só no(s)
 * perfil(is) que devem enxergar o dashboard (ex.: Coordenador).
 */
class PluginDashglpiProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Dashboard Pro', 'dashglpi');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Profile) || !$item->fields['id']) {
            return '';
        }

        return self::createTabEntry(self::getTypeName());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile)) {
            return false;
        }

        self::showForProfile((int) $item->getID());

        return true;
    }

    /**
     * Formulário da aba: um checkbox só, "Ver". Segue o mesmo padrão visual
     * (tab_cadre_fixe / tab_bg_1) das demais telas de direito do GLPI.
     *
     * Quem pode EDITAR aqui é quem já pode editar perfis (direito nativo
     * "profile", não um direito novo) - o mesmo controle de acesso que o
     * resto da tela de Configurar > Perfis já usa.
     */
    public static function showForProfile(int $profilesId): void
    {
        global $CFG_GLPI;

        $podeEditar = Session::haveRight(Profile::$rightname, UPDATE);
        $direitos   = ProfileRight::getProfileRights($profilesId, [PluginDashglpiDashboard::$rightname]);
        $temDireito = (int) ($direitos[PluginDashglpiDashboard::$rightname] ?? 0) & READ;

        echo "<form method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/dashglpi/front/profile.form.php'>";
        echo Html::hidden('profiles_id', ['value' => $profilesId]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Dashboard Pro', 'dashglpi') . "</th></tr>";
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ver o dashboard', 'dashglpi') . "</td>";
        echo "<td>";
        Html::showCheckbox([
            'name'     => 'plugin_dashglpi_dashboard',
            'checked'  => (bool) $temDireito,
            'readonly' => !$podeEditar,
        ]);
        echo "</td>";
        echo "</tr>";
        echo "</table>";

        if ($podeEditar) {
            echo "<div class='center mt-2'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }

        Html::closeForm();
    }
}
