<?php

/**
 * Teste standalone da LÓGICA do filtro de equipe/técnicos - sem GLPI.
 *
 * Não chama nada de inc/dashboard.class.php (aquele código precisa de $DB,
 * Session, etc.) - em vez disso, reimplementa aqui só o ALGORITMO de cada
 * peça (união de listas, filtro por ids, caso vazio) exatamente como
 * PluginDashglpiDashboard faz de verdade, pra conferir a regra isolada
 * ANTES de mexer no arquivo real. Ver:
 *   - getFilterableTechnicians() -> uniaoTecnicos() aqui
 *   - ticketIdCriteria()/resolveTicketIdsForTechnicians() -> filtrarChamados() aqui
 *
 * Uso:
 *   php tests/filtro_logica_test.php
 */

// --- reimplementação isolada da lógica (ver docblock acima) -----------------

/**
 * União de duas listas de técnicos ["id" => int, "nome" => string],
 * deduplicada por id e ordenada por nome - mesmo algoritmo de
 * PluginDashglpiDashboard::getFilterableTechnicians().
 */
function uniaoTecnicos(array $equipe, array $comChamadoAberto): array
{
    $porId = [];
    foreach (array_merge($equipe, $comChamadoAberto) as $tecnico) {
        $porId[$tecnico['id']] = $tecnico;
    }

    usort($porId, static fn(array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));

    return array_values($porId);
}

/**
 * Filtra uma lista de chamados (["id" => int, "tecnicos" => int[]]) pelos
 * ids de técnico informados - mesma semântica de
 * PluginDashglpiDashboard::resolveTicketIdsForTechnicians() +
 * ticketIdCriteria():
 *   null = sem filtro, todos os chamados voltam
 *   []   = filtro ativo, zero técnicos selecionados -> NENHUM chamado volta
 *   [..] = só chamados com pelo menos um dos técnicos informados
 */
function filtrarChamados(array $chamados, ?array $tecnicoIds): array
{
    if ($tecnicoIds === null) {
        return $chamados;
    }
    if ($tecnicoIds === []) {
        return [];
    }

    return array_values(array_filter(
        $chamados,
        static fn(array $chamado): bool => count(array_intersect($chamado['tecnicos'], $tecnicoIds)) > 0
    ));
}

// --- mini-runner -------------------------------------------------------------

$total = 0;
$falhas = [];

function verificar(string $descricao, callable $teste): void
{
    global $total, $falhas;
    $total++;
    try {
        $teste();
        echo "  ok    {$descricao}\n";
    } catch (Throwable $e) {
        $falhas[] = "{$descricao}: {$e->getMessage()}";
        echo "  FALHA {$descricao}\n        {$e->getMessage()}\n";
    }
}

function igual($esperado, $obtido, string $contexto = ''): void
{
    if ($esperado !== $obtido) {
        throw new RuntimeException(sprintf(
            '%sesperava %s, obteve %s',
            $contexto ? "{$contexto}: " : '',
            var_export($esperado, true),
            var_export($obtido, true)
        ));
    }
}

echo "\nLógica do filtro de equipe/técnicos (dashglpi)\n";
echo str_repeat('-', 70) . "\n";

// --- dados de exemplo, parecidos com o que as queries reais devolveriam ----

$minhaEquipe = [
    ['id' => 2, 'nome' => 'Bruna Alves'],
    ['id' => 1, 'nome' => 'Carlos Souza'],
];

$comChamadoAberto = [
    ['id' => 1, 'nome' => 'Carlos Souza'],   // já está na equipe - deve deduplicar
    ['id' => 3, 'nome' => 'Ana Pereira'],    // fora da equipe, mas com chamado aberto
];

$chamados = [
    ['id' => 101, 'tecnicos' => [1]],
    ['id' => 102, 'tecnicos' => [2]],
    ['id' => 103, 'tecnicos' => [3]],
    ['id' => 104, 'tecnicos' => [1, 3]], // dois técnicos no mesmo chamado
    ['id' => 105, 'tecnicos' => []],     // sem ninguém atribuído
];

// --- união --------------------------------------------------------------

verificar('uniao dedup por id', function () use ($minhaEquipe, $comChamadoAberto) {
    $uniao = uniaoTecnicos($minhaEquipe, $comChamadoAberto);
    igual(3, count($uniao), 'total apos dedup (Carlos aparece nos dois grupos)');
    $ids = array_column($uniao, 'id');
    sort($ids);
    igual([1, 2, 3], $ids);
});

verificar('uniao ordenada por nome', function () use ($minhaEquipe, $comChamadoAberto) {
    $uniao = uniaoTecnicos($minhaEquipe, $comChamadoAberto);
    $nomes = array_column($uniao, 'nome');
    igual(['Ana Pereira', 'Bruna Alves', 'Carlos Souza'], $nomes);
});

verificar('uniao com equipe vazia so traz quem tem chamado aberto', function () use ($comChamadoAberto) {
    $uniao = uniaoTecnicos([], $comChamadoAberto);
    igual(2, count($uniao));
});

verificar('uniao com as duas listas vazias e uma lista vazia', function () {
    igual([], uniaoTecnicos([], []));
});

// --- filtro de chamados ---------------------------------------------------

verificar('filtro null (sem filtro) devolve todos os chamados', function () use ($chamados) {
    igual(count($chamados), count(filtrarChamados($chamados, null)));
});

verificar('filtro [] (manual sem ninguem selecionado) nao devolve nenhum chamado', function () use ($chamados) {
    igual([], filtrarChamados($chamados, []));
});

verificar('filtro por um tecnico especifico pega so os chamados dele', function () use ($chamados) {
    $resultado = filtrarChamados($chamados, [2]);
    igual([102], array_column($resultado, 'id'));
});

verificar('filtro pega chamado com varios tecnicos se qualquer um bater', function () use ($chamados) {
    $resultado = filtrarChamados($chamados, [3]);
    igual([103, 104], array_column($resultado, 'id'), 'chamado 104 tem tecnico 3 junto com o 1');
});

verificar('filtro por tecnico sem chamado nenhum devolve lista vazia', function () use ($chamados) {
    igual([], filtrarChamados($chamados, [999]));
});

verificar('chamado sem tecnico atribuido nunca aparece em filtro nenhum', function () use ($chamados) {
    foreach ([[1], [2], [3], [1, 2, 3]] as $ids) {
        $resultado = filtrarChamados($chamados, $ids);
        if (in_array(105, array_column($resultado, 'id'), true)) {
            throw new RuntimeException('chamado 105 (sem tecnico) apareceu com filtro ' . implode(',', $ids));
        }
    }
});

verificar('filtro com a equipe inteira (uniao) pega todo mundo com chamado', function () use ($minhaEquipe, $comChamadoAberto, $chamados) {
    $uniao = uniaoTecnicos($minhaEquipe, $comChamadoAberto);
    $ids = array_column($uniao, 'id');
    $resultado = filtrarChamados($chamados, $ids);
    igual([101, 102, 103, 104], array_column($resultado, 'id'));
});

// --- resultado -------------------------------------------------------------

echo str_repeat('-', 70) . "\n";
if ($falhas === []) {
    echo "{$total} testes, todos passaram.\n\n";
    exit(0);
}

echo count($falhas) . " de {$total} FALHARAM:\n";
foreach ($falhas as $falha) {
    echo "  - {$falha}\n";
}
echo "\n";
exit(1);
