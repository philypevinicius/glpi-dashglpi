# DashGLPI — Dashboard Pro para GLPI 11

Dashboard avançado e independente para visualização de dados do GLPI, com interface glassmorphism, modo escuro/claro, modo TV e tema cyberpunk para inventário de ativos.

**Autor:** Diogo Berlanda
**Licença:** GPLv3+
**Compatibilidade:** GLPI 11.0.x
**Repositório:** https://github.com/diberlanda95/dashglpi

---

## Funcionalidades

### Visão Geral (Dashboard)
- **6 KPIs em tempo real:** Total de chamados, Em andamento, Taxa de conclusão, SLA vencido, Tempo médio de resolução, Reabertos
- **4 cards de detalhe:** Novos chamados, Com técnico, Aguardando, Resolvidos
- **3 gráficos interativos:**
  - Fluxo de Criação (linha, últimos 30 dias)
  - Top 5 Categorias (barra horizontal)
  - Abertos vs Solucionados (barra comparativa, últimos 6 meses)
- **Tabela de Atividade Recente** com os 5 últimos chamados

### Monitor de SLA
- Percentual do prazo já consumido por chamado, calculado a partir dos campos
  nativos do GLPI (`date`, `time_to_resolve`, `solvedate` - sem depender de
  nenhuma extensão de SLA) - ver `PluginDashglpiDashboard::calcularPercentualSla()`
- Barra de progresso colorida por faixa de percentual: azul (<25%) → verde
  (25-50%) → amarelo (50-75%) → laranja (75-90%) → vermelho (90-100%) → roxo
  (>100%, estourado) - ver `PluginDashglpiDashboard::corSla()`
- Contadores de SLA (Crítico/Atenção/No Prazo) e lista de chamados próximos ao
  vencimento com countdown em tempo real, ambos a partir dos mesmos dados reais
- Chamados sem SLA aplicado (`time_to_resolve` vazio) não entram na lista nem
  contam nos totais - "sem SLA" é diferente de "SLA tranquilo"

### Ranking de Técnicos (Gamificação)
- Ranking mensal baseado em chamados resolvidos
- Sistema de pontos (10 pts por chamado resolvido)
- Destaque visual para o Técnico do Mês
- Barras de progresso animadas

### Inventário de Ativos (Grid Cyberpunk)
- Grid de cards com estilo cyberpunk/neon
- Indicador visual de disco (barra de uso com cores por criticidade)
- Modal detalhado com: OS, CPU, RAM, Disco, Serial, Localização
- Detecção automática de ícone por tipo (desktop, laptop, servidor)

### Recursos Gerais
- **Modo Escuro/Claro** com persistência via localStorage
- **Modo TV** com fullscreen e rotação automática entre seções (15s)
- **Atualização automática** dos dados a cada 60 segundos
- **Relógio** em tempo real no header
- **Painel de Notificações** (demonstração)

### Controle de acesso

O dashboard tem um direito PRÓPRIO, `plugin_dashglpi_dashboard` (READ), separado
de qualquer direito de editar/pausar chamado - controla só quem PODE VER o
dashboard. Registrado em todos os perfis com valor 0 (sem acesso) na
instalação; fica em **Configurar > Perfis > [perfil] > aba "Dashboard Pro"**,
um único checkbox "Ver o dashboard". Sem o direito, o link do menu nem aparece
e a URL direta responde 403 - os dois pontos (`hook.php` e
`front/dashboard.php`) checam o mesmo direito de forma independente.

### Filtro de equipe/técnicos

Acima da tabela "Todos os Chamados": três opções, sempre a mesma primeira que
vale -
- **Minha equipe (N)** - só aparece para quem é gestor de algum Grupo do
  GLPI (`glpi_groups_users.is_manager = 1`); mostra os chamados de todos os
  membros desse(s) grupo(s)
- **Todos os técnicos** - remove o filtro
- **Escolher técnicos...** - painel com um checkbox por técnico (união de
  "minha equipe" com quem tem chamado aberto atribuído, mesmo fora da
  equipe), seleção livre, aplicada só ao clicar "Aplicar"

O estado (`?equipe=todos|minha|manual&tecnicos=1,2,3`) fica na URL - dá pra
favoritar/compartilhar o link já filtrado. Os KPIs, gráficos e o Monitor de
SLA recalculam de acordo com o filtro ativo, não só a tabela. Filtro manual
sem nenhum técnico selecionado mostra aviso, não uma tabela vazia sem
explicação.

---

## Instalação

### Pré-requisitos
- GLPI 11.0.x instalado e funcionando
- Acesso de administrador ao GLPI

### Passos

1. Copie a pasta `dashglpi/` para o diretório de plugins do GLPI:
   ```bash
   cp -r dashglpi/ /usr/share/glpi/plugins/
   ```

2. Acesse o GLPI como administrador

3. Vá em **Configurar > Plugins**

4. Localize "Dashboard GLPI Pro" e clique em **Instalar**, depois **Ativar**

5. O dashboard aparecerá no menu **Assistência > Dashboard Pro**

> **Nota:** Se o ambiente usa OPcache com `validate_timestamps=Off`, reinicie o PHP-FPM após copiar os arquivos:
> ```bash
> pkill -USR2 php-fpm
> ```

---

## Estrutura de Arquivos

```
dashglpi/
├── setup.php                  # Registro do plugin, versão, hooks
├── hook.php                   # Install/uninstall (direito), menu (redefine_menus)
├── front/
│   ├── dashboard.php          # Página standalone (HTML completo, autenticada)
│   └── profile.form.php       # Grava o direito do dashboard num perfil
├── ajax/
│   └── dashboard.php          # Endpoint AJAX (5 actions, retorna JSON)
├── inc/
│   ├── dashboard.class.php    # Backend: queries SQL via $DB->request(), SLA, filtro
│   └── profile.class.php      # Aba "Dashboard Pro" no item Profile
├── tests/
│   └── filtro_logica_test.php # Teste standalone (sem GLPI) da lógica de filtro
├── public/
│   ├── css/
│   │   └── style.css          # Estilos (glassmorphism, cyberpunk, dark/light)
│   └── js/
│       ├── script.js          # Lógica frontend (charts, modals, filtro, AJAX)
│       └── menu.js            # Script mínimo: força links em nova aba
└── README.md
```

> Bootstrap 5, FontAwesome 6 e Chart.js vêm de **CDN** (jsDelivr/cdnjs), não de
> `public/vendor/` local - essa pasta era documentada mas nunca chegou a
> existir no repositório, o que deixava a página inteira sem grid, sem ícone
> e sem gráfico algum (nenhum arquivo de biblioteca para carregar). Trocar por
> CDN foi a correção pragmática; vendorizar de verdade fica como próximo passo
> se algum dia o ambiente não puder depender de CDN externo.

---

## Arquitetura

### Fluxo de Dados

```
Navegador (front/dashboard.php)
    │
    ├─ JS (script.js) ──→ AJAX GET /ajax/dashboard.php?action=...
    │                           │
    │                           └─→ PluginDashglpiDashboard (inc/)
    │                                   │
    │                                   └─→ $DB->request() (tabelas GLPI nativas)
    │
    └─ Resposta JSON ──→ Atualiza KPIs, gráficos, tabelas
```

### Design Standalone

O dashboard renderiza seu próprio HTML completo — **não usa** o header/footer do GLPI. Isso permite:
- Layout fullscreen sem interferência visual do GLPI
- Modo TV dedicado
- Tema independente (dark/light)

A autenticação é garantida via `Session::checkLoginUser()` no topo de cada arquivo PHP.

### Isolamento de CSS/JS

O plugin **não injeta CSS** globalmente no GLPI (evita conflitos visuais). Apenas um JS mínimo (`menu.js`, 10 linhas) é carregado globalmente para forçar o link do menu a abrir em nova aba.

Bibliotecas de terceiros (Bootstrap, FontAwesome, Chart.js) vêm de CDN - ver a nota na seção "Estrutura de Arquivos" acima.

---

## API AJAX

Endpoint: `plugins/dashglpi/ajax/dashboard.php`

Todas as requests requerem sessão GLPI autenticada (cookie de sessão) **e** o
direito `plugin_dashglpi_dashboard` (ver "Controle de acesso" acima) - sem
ele, qualquer action devolve 403, mesmo para Super-Admin.

Filtro de equipe/técnicos, aceito por `dashboard_data` e `tickets_list`:
- `?equipe=todos` (padrão, se omitido) - sem filtro
- `?equipe=minha` - só a equipe do usuário logado (grupos em que é gestor)
- `?equipe=manual&tecnicos=1,2,3` - só os ids informados; `tecnicos=` vazio
  filtra para ZERO chamados (não "sem filtro")

| Action | Método | Descrição | Resposta |
|--------|--------|-----------|----------|
| `dashboard_data` | GET | KPIs + dados dos gráficos (aceita filtro) | `{cards_top, cards_bottom, charts}` |
| `get_ranking` | GET | Ranking de técnicos do mês | `[{name, avatar, tickets, points, color}]` |
| `tickets_list` | GET | Lista de chamados ativos, máx. 100 (aceita filtro) | `[{id, name, status, date, category, user_name, time_to_resolve, solvedate, sla_percentual, sla_cor, tecnicos, tecnico}]` |
| `assets_list` | GET | Lista de computadores (máx. 100) | `[{id, name, serial, location, model, os_name, cpu, ram_total, disk_total, disk_free}]` |
| `filter_options` | GET | Dados pro filtro de equipe/técnicos | `{minha_equipe: {disponivel, tecnicos}, todos_tecnicos}` |

`sla_percentual` é `null` (e `sla_cor` é `"none"`) quando o chamado não tem
`time_to_resolve` - sem SLA aplicado, sem barra pra desenhar. `tecnicos` é a
lista de nomes atribuídos (pode ter mais de um); `tecnico` é a mesma lista
já unida em texto, pronta pra exibir.

### Exemplo de uso

```javascript
const response = await fetch('/plugins/dashglpi/ajax/dashboard.php?action=dashboard_data&equipe=minha');
const data = await response.json();
// data.cards_top.total → total de chamados DA EQUIPE do usuário logado
// data.charts.trend_line → [{dia, total}, ...]
```

### Tratamento de erros

- **400** — Action inválida: `{"error": "Ação inválida."}`
- **403** — Sem o direito do dashboard: `{"error": "Acesso negado."}`
- **500** — Erro interno: `{"error": "Erro interno do servidor."}` (detalhes logados no `php-errors.log`)
- **302** — Sem autenticação: redirect para login

---

## Queries e Performance

Todas as queries usam `$DB->request()` com arrays de critérios (obrigatório no GLPI 11). Nenhum SQL raw.

### Otimização de Assets (Bulk Queries)

O endpoint `assets_list` busca dados complementares (OS, CPU, RAM, Disco) usando **4 queries bulk** em vez de queries individuais por asset:

| Dado | Tabela | Estratégia |
|------|--------|------------|
| OS | `glpi_items_operatingsystems` + `glpi_operatingsystems` | LEFT JOIN, WHERE IN |
| CPU | `glpi_items_deviceprocessors` + `glpi_deviceprocessors` | INNER JOIN, WHERE IN |
| RAM | `glpi_items_devicememories` | SUM + GROUP BY |
| Disco | `glpi_items_disks` | SUM (total + free) + GROUP BY |

Resultado: **5 queries fixas** independente do volume de assets (vs. N*4+1 na abordagem ingênua).

### Respeito a Entidades

Todas as queries usam `getEntitiesRestrictCriteria()` para filtrar dados conforme a entidade ativa do usuário logado.

---

## Temas e Personalização

### Variáveis CSS principais

O tema é controlado por CSS custom properties definidas em `style.css`:

| Variável | Uso |
|----------|-----|
| `--bg-body` | Fundo da página |
| `--card-bg` | Fundo dos cards |
| `--glass-bg` | Fundo glassmorphism (translúcido) |
| `--text-main` | Texto principal |
| `--text-sec` | Texto secundário |
| `--text-muted` | Texto desabilitado |
| `--border-color` | Bordas |
| `--primary` | Cor primária (azul) |
| `--success` | Verde |
| `--warning` | Amarelo |
| `--danger` | Vermelho |
| `--neon-blue` | Azul neon (cyberpunk) |
| `--neon-pink` | Rosa neon (cyberpunk) |

### Modo Claro

Ativado via classe `light-mode` no `<body>`. As variáveis são sobrescritas para tons claros.

---

## Seções de Demonstração

- **Notificações** — lista fixa de 3 notificações. Marcado com badge "DEMO". Serve como placeholder para uma futura integração com o sistema de notificações do GLPI.

O Monitor de SLA usava dados 100% fictícios (badge "DEMONSTRAÇÃO" no HTML) -
não usa mais: o badge foi removido e os dados vêm de `tickets_list` (chamados
reais), ver "Monitor de SLA" mais acima.

---

## Desenvolvimento

### Requisitos para desenvolvimento
- GLPI 11.0.x com acesso de administrador
- Navegador moderno (Chrome, Firefox, Edge)

### Limpeza de cache após alterações

Se o ambiente usa OPcache:
```bash
# PHP
pkill -USR2 php-fpm

# Nginx (se aplicável)
rm -rf /var/lib/nginx/cache/public/*
```

### Adicionando novos endpoints AJAX

1. Criar o método estático em `inc/dashboard.class.php`
2. Adicionar o `case` no `switch` de `ajax/dashboard.php`
3. Chamar via `fetch()` no `script.js`

### Segurança

- `Session::checkLoginUser()` em todos os entry points PHP
- `escHtml()` no JS para sanitização de output (prevenção de XSS)
- Sem exposição de informações de debug em respostas de erro
- CSRF compliant (declarado via `$PLUGIN_HOOKS['csrf_compliant']`)
