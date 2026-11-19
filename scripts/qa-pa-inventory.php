<?php
declare(strict_types=1);

// ============================================================================
// QA do inventario de PA completo e das preferencias (desenho do F10, 3.3 e
// 3.3.1; lote F10-3)
//
// Sem banco:
// 1. estrutura da migration 022, do rollback, das linhas do ensaio e da
//    consulta de verificacao.
// As consultas SQL rodam em MySQL no ensaio (scripts/ensaio-migration.sh) e na
// integracao do lote.
//
// Uso: php scripts/qa-pa-inventory.php
// ============================================================================

function assert_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n"
            . '  esperado: ' . var_export($expected, true) . "\n"
            . '  obtido:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
    $GLOBALS['qa_checks'] = ($GLOBALS['qa_checks'] ?? 0) + 1;
}

/** SQL sem comentarios de linha (-- ...), para as checagens estruturais. */
function qa_sql_without_comments(string $sql): string {
    return (string)preg_replace('/^\s*--.*$/m', '', $sql);
}

$root = dirname(__DIR__);

// ----------------------------------------------------------------------------
// 1. Migration 022, rollback, linhas do ensaio e verificacao (estrutura)
// ----------------------------------------------------------------------------
$migration = qa_sql_without_comments((string)file_get_contents($root . '/migrations/20261119_022_pa_inventory.sql'));
$rollback = qa_sql_without_comments((string)file_get_contents($root . '/migrations/rollback/20261119_022_pa_inventory_down.sql'));
$lines = (string)file_get_contents($root . '/migrations/rollback/20261119_022_ensaio_linhas_inv.sql');
$check = (string)file_get_contents($root . '/migrations/verificacao/20261119_022_pa_inventory_check.sql');

assert_same(1, substr_count($migration, "SET time_zone = 'America/Sao_Paulo';"), 'migration 022: fuso da sessao (A4)');
foreach (['seat_type', 'can_lend', 'reserved_team'] as $column) {
    assert_same(1, preg_match("/TABLE_NAME = 'portal_pa_inventory' AND COLUMN_NAME = '{$column}'\\s*\\);\\s*SET @add_{$column}_sql := IF\\(\\s*@has_{$column} = 0,/", $migration), "migration 022: coluna {$column} so se nao existir (idempotente)");
}
assert_same([1, 1, 1], [
    substr_count($migration, "ADD COLUMN seat_type ENUM(''flexible'', ''fixed'') NOT NULL DEFAULT ''flexible''"),
    substr_count($migration, 'ADD COLUMN can_lend TINYINT(1) NOT NULL DEFAULT 0'),
    substr_count($migration, "ADD COLUMN reserved_team ENUM(''critico'', ''hibrido'') NULL DEFAULT NULL"),
], 'migration 022: tipo flexivel por padrao, sem emprestimo e sem reserva (nenhum PA muda no deploy)');
assert_same(3, substr_count($migration, 'ALGORITHM=INSTANT'), 'migration 022: as tres colunas so no metadado (INSTANT, explicito)');
assert_same([1, 1, 2], [
    substr_count($migration, "ADD CONSTRAINT ck_pa_inventory_reserved CHECK (reserved_team IS NULL OR seat_type = ''fixed'')"),
    substr_count($migration, "ADD CONSTRAINT ck_pa_inventory_lend CHECK (can_lend IN (0, 1) AND (can_lend = 0 OR (seat_type = ''fixed'' AND reserved_team IS NULL)))"),
    substr_count($migration, 'ALGORITHM=COPY'),
], 'migration 022: reserva so em fixo, emprestimo so em fixo sem reserva (COPY explicito: o MySQL confere as linhas)');
foreach (['ck_pa_inventory_reserved', 'ck_pa_inventory_lend'] as $constraint) {
    assert_same(1, substr_count($migration, "CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = '{$constraint}'"), "migration 022: {$constraint} so se nao existir");
}
assert_same(1, substr_count($migration, 'CREATE TABLE portal_pa_preferences ('), 'migration 022: cria as preferencias');
assert_same(1, preg_match("/WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'portal_pa_preferences'\\s*\\);\\s*SET @create_preferences_sql := IF\\(\\s*@has_preferences_table = 0,/", $migration), 'migration 022: preferencias so se nao existir (idempotente)');
assert_same([1, 1, 1, 1], [
    substr_count($migration, 'UNIQUE KEY uq_pa_preference (pa_id, employee_id)'),
    substr_count($migration, 'KEY idx_pa_preference_employee (employee_id)'),
    substr_count($migration, 'CONSTRAINT fk_pa_preference_pa FOREIGN KEY (pa_id) REFERENCES portal_pa_inventory (id) ON DELETE RESTRICT'),
    substr_count($migration, 'CONSTRAINT fk_pa_preference_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT'),
], 'migration 022: uma preferencia por par, indice da FK do cadastro, FKs com RESTRICT');
assert_same(0, preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP|TRUNCATE)\b/i', $migration), 'migration 022: so estrutura, nenhuma linha muda');
assert_same(0, preg_match('/\b(note|notes|observacao|nome|employee_name|login)\b/i', (string)preg_replace("/COMMENT\\s*(=\\s*)?''[^']*''/", '', $migration)), 'migration 022: nenhuma coluna de texto livre nem nome de pessoa');

assert_same(1, substr_count($rollback, "SIGNAL SQLSTATE '45000'"), 'rollback 022: para se ja houver copia *_bkp_022');
$copyInventory = strpos($rollback, 'CREATE TABLE portal_pa_inventory_bkp_022');
$copyPreferences = strpos($rollback, 'CREATE TABLE portal_pa_preferences_bkp_022');
$dropPreferences = strpos($rollback, "'DROP TABLE portal_pa_preferences'");
$dropLend = strpos($rollback, "'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_lend'");
$dropReserved = strpos($rollback, "'ALTER TABLE portal_pa_inventory DROP CHECK ck_pa_inventory_reserved'");
$dropColumns = strpos($rollback, "CONCAT('ALTER TABLE portal_pa_inventory ', GROUP_CONCAT(CONCAT('DROP COLUMN ', COLUMN_NAME)");
assert_same(true, strpos($rollback, 'CALL rollback_022_confere_copias();') < $copyInventory && $copyInventory < $copyPreferences && $copyPreferences < $dropPreferences && $dropPreferences < $dropLend && $dropLend < $dropReserved && $dropReserved < $dropColumns, 'rollback 022: confere, copia o inventario e as preferencias, e so entao tira a tabela, as restricoes e as colunas');
assert_same(1, substr_count($rollback, 'SELECT id, pa_number, seat_type, can_lend, reserved_team'), 'rollback 022: a copia do inventario guarda so ID, numero, tipo, emprestimo e reserva');
assert_same(0, preg_match('/\b(DELETE|UPDATE|TRUNCATE)\b/i', $rollback), 'rollback 022: nenhuma linha apagada nem alterada (so copias e estrutura)');

assert_same(3, substr_count($lines, "DATABASE() LIKE 'ensaio\\_%'"), 'linhas do ensaio: cada comando so no banco ensaio_');
assert_same(2, substr_count($lines, "AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()))"), 'linhas do ensaio: reserva e fixo so em PA sem vinculo vigente ou futuro');
assert_same(0, preg_match('/\b(DELETE|TRUNCATE|DROP)\b|portal_pa_assignments\s+SET|funcionarios\s+SET/i', qa_sql_without_comments($lines)), 'linhas do ensaio: nenhum vinculo nem cadastro alterado');

assert_same(0, preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', qa_sql_without_comments($check)), 'verificacao 022 so le');
foreach (['colunas_022', 'checks_022', 'tabela_022', 'copias_de_rollback_022', 'reservados_critico', 'reservados_hibrido', 'fixos_que_emprestam', 'preferencias_em_pa_reservado', 'preferencias_fora_do_grupo_comum', 'reservados_com_vinculo_vigente', 'fixos_com_vinculos_sobrepostos'] as $count) {
    assert_same(1, preg_match('/AS ' . $count . '(,|;|\'|$|\s)/m', $check), "verificacao 022: conta {$count}");
}
assert_same(0, preg_match('/\b(nome|employee_name|employee_login|ad_login|notes)\b/i', qa_sql_without_comments($check)), 'verificacao 022: nenhuma coluna de nome, login ou observacao');

// ----------------------------------------------------------------------------
// 2. Regras puras do inventario (PaInventoryRules)
// ----------------------------------------------------------------------------
require_once $root . '/services/PaInventoryRules.php';
require_once $root . '/services/PaDistributionEngine.php';

$seatOutcome = static function (array $data): string {
    try {
        $seat = PaInventoryRules::seat($data);
        return $seat['seat_type'] . '|' . ($seat['can_lend'] ? 'empresta' : '-') . '|' . ($seat['reserved_team'] ?? '-');
    } catch (InvalidArgumentException $error) {
        return 'erro: ' . $error->getMessage();
    }
};
assert_same([
    'flexible|-|-',
    'fixed|-|-',
    'fixed|empresta|-',
    'fixed|-|critico',
    'fixed|-|-',
    'erro: Tipo do PA inválido: use flexível ou fixo.',
    'erro: Tipo do PA inválido: use flexível ou fixo.',
    'erro: O Híbrido usa PA fixo por pessoa (vínculo do Mapa PA): a reserva por equipe é só do Crítico.',
    'erro: Reserva inválida.',
    'erro: Reserva só em PA fixo.',
    'erro: Empréstimo só em PA fixo sem reserva: o dono ausente empresta o PA ao grupo comum.',
    'erro: Empréstimo só em PA fixo sem reserva: o dono ausente empresta o PA ao grupo comum.',
    'erro: Empréstimo inválido.',
], [
    $seatOutcome(['seat_type' => 'flexible']),
    $seatOutcome(['seat_type' => 'fixed', 'can_lend' => false, 'reserved_team' => null]),
    $seatOutcome(['seat_type' => 'fixed', 'can_lend' => true]),
    $seatOutcome(['seat_type' => 'fixed', 'reserved_team' => 'critico']),
    $seatOutcome(['seat_type' => 'fixed', 'reserved_team' => '']),
    $seatOutcome([]),
    $seatOutcome(['seat_type' => 'FIXED']),
    $seatOutcome(['seat_type' => 'fixed', 'reserved_team' => 'hibrido']),
    $seatOutcome(['seat_type' => 'fixed', 'reserved_team' => 'n1']),
    $seatOutcome(['seat_type' => 'flexible', 'reserved_team' => 'critico']),
    $seatOutcome(['seat_type' => 'flexible', 'can_lend' => true]),
    $seatOutcome(['seat_type' => 'fixed', 'can_lend' => true, 'reserved_team' => 'critico']),
    $seatOutcome(['seat_type' => 'fixed', 'can_lend' => 1]),
], 'seat: as combinacoes validas e as recusas (reserva so em fixo, emprestimo so em fixo sem reserva, Hibrido na opcao (c) da D-F10-19)');
assert_same(['critico'], PaInventoryRules::RESERVED_TEAMS, 'D-F10-19 revista: so o Critico tem reserva por equipe');
assert_same([
    ['seat_type' => 'fixed', 'can_lend' => true, 'reserved_team' => null],
    ['seat_type' => 'flexible', 'can_lend' => false, 'reserved_team' => null],
    ['seat_type' => 'fixed', 'can_lend' => false, 'reserved_team' => 'critico'],
], [
    PaInventoryRules::storedSeat(['seat_type' => 'fixed', 'can_lend' => '1', 'reserved_team' => '']),
    PaInventoryRules::storedSeat([]),
    PaInventoryRules::storedSeat(['seat_type' => 'fixed', 'can_lend' => 0, 'reserved_team' => 'critico']),
], 'storedSeat: linha do banco no formato do seat (0/1 vira booleano; vazio vira sem reserva)');
assert_same([['can_lend'], ['seat_type', 'reserved_team'], []], [
    PaInventoryRules::changedFields(['seat_type' => 'fixed', 'can_lend' => false, 'reserved_team' => null], ['seat_type' => 'fixed', 'can_lend' => true, 'reserved_team' => null]),
    PaInventoryRules::changedFields(['seat_type' => 'flexible', 'can_lend' => false, 'reserved_team' => null], ['seat_type' => 'fixed', 'can_lend' => false, 'reserved_team' => 'critico']),
    PaInventoryRules::changedFields(['seat_type' => 'fixed', 'can_lend' => true, 'reserved_team' => null], ['seat_type' => 'fixed', 'can_lend' => true, 'reserved_team' => null]),
], 'changedFields: so os campos que mudaram, na ordem tipo, emprestimo, reserva');
$prefOutcome = static function (mixed $value): string {
    try {
        return implode(',', PaInventoryRules::preferenceIds($value));
    } catch (InvalidArgumentException $error) {
        return 'erro';
    }
};
assert_same(['', '3,7,12', '5', 'erro', 'erro', 'erro', 'erro', 'erro', 'erro', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20', 'erro'], [
    $prefOutcome([]), $prefOutcome([12, '7', 3]), $prefOutcome(['5']), $prefOutcome([5, '5']), $prefOutcome([0]), $prefOutcome(['01']), $prefOutcome([1.5]), $prefOutcome(['a' => 1]), $prefOutcome('1,2'), $prefOutcome(range(1, 20)), $prefOutcome(range(1, 21)),
], 'preferenceIds: lista de 0 a 20 IDs positivos, sem repeticao, em ordem');
assert_same([true, true, false, false, false, false, false], [
    PaInventoryRules::canPrefer(['team' => 'n1', 'grupo_escala' => 'comum', 'fora_mapa_escala' => 0]),
    PaInventoryRules::canPrefer(['team' => 'n2', 'grupo_escala' => 'comum', 'fora_mapa_escala' => '0']),
    PaInventoryRules::canPrefer(['team' => 'n2', 'grupo_escala' => 'separado', 'fora_mapa_escala' => 0]),
    PaInventoryRules::canPrefer(['team' => 'critico', 'grupo_escala' => 'separado', 'fora_mapa_escala' => 0]),
    PaInventoryRules::canPrefer(['team' => 'hibrido', 'grupo_escala' => 'comum', 'fora_mapa_escala' => 0]),
    PaInventoryRules::canPrefer(['team' => 'lideranca', 'grupo_escala' => 'comum', 'fora_mapa_escala' => 0]),
    PaInventoryRules::canPrefer(['team' => 'n1', 'grupo_escala' => 'comum', 'fora_mapa_escala' => 1]),
], 'canPrefer: so N1 e N2 do grupo comum, fora do "fora do Mapa" (D-F9-12)');
$order = [['pa_number' => '1717', 'grid_row' => 2, 'grid_column' => 1], ['pa_number' => '1725', 'grid_row' => 1, 'grid_column' => 8], ['pa_number' => '1732', 'grid_row' => 1, 'grid_column' => 1], ['pa_number' => '1730', 'grid_row' => 1, 'grid_column' => 1]];
usort($order, [PaInventoryRules::class, 'inventoryOrder']);
assert_same(['1730', '1732', '1725', '1717'], array_column($order, 'pa_number'), 'inventoryOrder: fileira, coluna e numero (a ordem do motor)');

// ----------------------------------------------------------------------------
// 3. Motor: os reservados ocupados pelas pessoas da equipe no dia (D-F10-19)
// ----------------------------------------------------------------------------
assert_same('f10-pa-2', PaDistributionEngine::VERSION, 'motor: versao nova com os reservados pelos papeis');
assert_same([
    ['occupants' => [1723 => 31, 1722 => 35], 'without_seat' => [32]],
    ['occupants' => [1723 => 31, 1722 => null], 'without_seat' => []],
    ['occupants' => [], 'without_seat' => [31]],
    ['occupants' => [1723 => null, 1722 => null], 'without_seat' => []],
], [
    PaDistributionEngine::reservedSeating([1723, 1722], [31, 35, 32]),
    PaDistributionEngine::reservedSeating([1723, 1722], [31]),
    PaDistributionEngine::reservedSeating([], [31]),
    PaDistributionEngine::reservedSeating([1723, 1722], []),
], 'reservedSeating: o 1o PA com a 1a pessoa; PA sem pessoa fica vazio; pessoa sem PA sobra');
assert_same([31, 35, 32, 34, 30], PaDistributionEngine::orderReservedPeople([
    ['employee_id' => 30, 'name' => 'Zeta', 'role_order' => null, 'role_id' => null],
    ['employee_id' => 32, 'name' => 'Gama', 'role_order' => 2, 'role_id' => 2],
    ['employee_id' => 34, 'name' => 'Álfa', 'role_order' => null, 'role_id' => null],
    ['employee_id' => 35, 'name' => 'Eta', 'role_order' => 2, 'role_id' => 2],
    ['employee_id' => 31, 'name' => 'Beta', 'role_order' => 1, 'role_id' => 1],
]), 'orderReservedPeople: com papel pela ordem do papel, depois sem papel; no empate, o nome normalizado e o ID');
assert_same([7, 5, 6], PaDistributionEngine::orderReservedPeople([
    ['employee_id' => 6, 'name' => 'Mesmo', 'role_order' => 1, 'role_id' => 9],
    ['employee_id' => 5, 'name' => 'Mesmo', 'role_order' => 1, 'role_id' => 9],
    ['employee_id' => 7, 'name' => 'Mesmo', 'role_order' => 1, 'role_id' => 2],
]), 'orderReservedPeople: mesma ordem de papel, o ID do papel; mesmo nome, o ID da pessoa');

$baseSeats = [
    ['pa' => 10, 'row' => 1, 'column' => 1, 'type' => 'flexible'],
    ['pa' => 20, 'row' => 1, 'column' => 2, 'type' => 'fixed', 'reserved_for' => 'critical'],
    ['pa' => 30, 'row' => 1, 'column' => 3, 'type' => 'fixed', 'reserved_for' => 'critical'],
    ['pa' => 40, 'row' => 2, 'column' => 1, 'type' => 'flexible'],
];
$run = static fn (array $extra = []): array => PaDistributionEngine::distribute(['days' => ['2026-11-18'], 'seats' => $baseSeats, 'people' => [1 => 'Ana', 2 => 'Bia'], 'presence' => ['2026-11-18' => [1 => 'onsite', 2 => 'onsite']]] + $extra)['days']['2026-11-18'];
$script = $run();
assert_same([[null, 'critical', 'reserved'], [null, 'critical', 'reserved'], 2, []], [array_values($script['occupants'][20]), array_values($script['occupants'][30]), $script['capacity'], $script['status']], 'motor sem reserved_people: o reservado fica ocupado sem pessoa, como no script (paridade)');
$withRoles = $run(['reserved_people' => ['2026-11-18' => ['critical' => [7]]]]);
assert_same([[7, 'critical', 'reserved'], [null, 'critical', 'reserved'], 2, [1, 2], []], [array_values($withRoles['occupants'][20]), array_values($withRoles['occupants'][30]), $withRoles['capacity'], [$withRoles['occupants'][10]['employee_id'], $withRoles['occupants'][40]['employee_id']], $withRoles['status']], 'motor com reserved_people: o 1o reservado com a pessoa do papel; o outro reservado fica vazio e nao empresta (capacidade igual)');
// Reservado vazio nao empresta (opcao (b)): com mais gente do grupo comum que flexiveis, sobra quem nao cabe.
$crowded = PaDistributionEngine::distribute(['days' => ['2026-11-18'], 'seats' => $baseSeats, 'people' => [1 => 'Ana', 2 => 'Bia', 3 => 'Cid'], 'presence' => ['2026-11-18' => [1 => 'onsite', 2 => 'onsite', 3 => 'onsite']], 'reserved_people' => ['2026-11-18' => ['critical' => [7]]]])['days']['2026-11-18'];
assert_same([null, [3], ['PA INSUFICIENTE'], 2], [$crowded['occupants'][30]['employee_id'], $crowded['no_pa'], $crowded['status'], $crowded['capacity']], 'motor: o reservado vazio nao empresta ao grupo comum, nem com gente sem PA');
$short = $run(['reserved_people' => ['2026-11-18' => ['critical' => [7, 8, 9]]]]);
assert_same([[7, 8], [['code' => 'reserved_short', 'reserved_for' => 'critical', 'count' => 1]], ['ATENCAO CONFIG']], [[$short['occupants'][20]['employee_id'], $short['occupants'][30]['employee_id']], array_values(array_filter($short['alerts'], static fn (array $a): bool => $a['code'] === 'reserved_short')), $short['status']], 'motor: pessoa da equipe sem PA reservado vira reserved_short (ATENCAO CONFIG)');
$otherDay = PaDistributionEngine::distribute(['days' => ['2026-11-18', '2026-11-19'], 'seats' => $baseSeats, 'people' => [], 'presence' => [], 'reserved_people' => ['2026-11-19' => ['critical' => [7]]]])['days'];
assert_same([null, 7], [$otherDay['2026-11-18']['occupants'][20]['employee_id'], $otherDay['2026-11-19']['occupants'][20]['employee_id']], 'motor: reserved_people vale so no dia dele');
$reservedError = static function (array $reserved, array $people = []) use ($baseSeats): string {
    try {
        PaDistributionEngine::distribute(['days' => ['2026-11-18'], 'seats' => $baseSeats, 'people' => $people, 'presence' => [], 'reserved_people' => $reserved]);
        return 'aceito';
    } catch (InvalidArgumentException $error) {
        return $error->getMessage();
    }
};
assert_same([
    'reserved_people: reserva invalida (so hybrid/critical, com lista de IDs)',
    'reserved_people: pessoa repetida no dia ou do grupo comum',
    'reserved_people: pessoa repetida no dia ou do grupo comum',
    'reserved_people.id: inteiro positivo obrigatorio',
    'reserved_people: data invalida (Y-m-d)',
    'reserved_people: reserva invalida (so hybrid/critical, com lista de IDs)',
    'aceito',
], [
    $reservedError(['2026-11-18' => ['critico' => [7]]]),
    $reservedError(['2026-11-18' => ['critical' => [7, 7]]]),
    $reservedError(['2026-11-18' => ['critical' => [1]]], [1 => 'Ana']),
    $reservedError(['2026-11-18' => ['critical' => [0]]]),
    $reservedError(['18/11/2026' => ['critical' => [7]]]),
    $reservedError(['2026-11-18' => ['critical' => ['x' => 7]]]),
    $reservedError(['2026-11-18' => ['critical' => [7], 'hybrid' => [8]]]),
], 'motor: reserved_people validado na fronteira (reserva, repeticao, grupo comum, ID, data e lista)');

// ----------------------------------------------------------------------------
// 4. Endpoint e servico (leitura do codigo): permissao antes das acoes novas,
//    auditoria so com IDs, sem nome nem login
// ----------------------------------------------------------------------------
$endpoint = (string)file_get_contents($root . '/api/portal/pa_map.php');
$gate = strpos($endpoint, "if (!portal_role_can(\$role, 'pa_map.manage')) {");
$input = strpos($endpoint, '$data = portal_json_input(PaMapService::MAX_PAYLOAD_BYTES);');
assert_same(true, $gate !== false && $gate < $input && $input < strpos($endpoint, "if (\$action === 'inventory_save') {") && $input < strpos($endpoint, "if (\$action === 'preferences_save') {"), 'endpoint: inventario e preferencias depois da permissao pa_map.manage e da leitura do corpo com CSRF');
assert_same([1, 1, 1, 1], [
    substr_count($endpoint, "audit_log('PA_INVENTARIO_ALTERADO', 'Inventario de PA alterado. PA ' . \$result['pa_number'] . ': ' . \$describe(\$result['before']) . ' -> ' . \$describe(\$result['after']), 'WARNING');"),
    substr_count($endpoint, "audit_log('PA_PREFERENCIA_ALTERADA', 'Preferencias de PA alteradas. PA ' . \$result['pa_number'] . ', incluidos ID '"),
    substr_count($endpoint, "if (\$result['changed'] !== []) {"),
    substr_count($endpoint, "if (\$result['added'] !== [] || \$result['removed'] !== []) {"),
], 'endpoint: auditoria do inventario (WARNING) e das preferencias (INFO) so quando muda, com o PA e os IDs');
assert_same(0, preg_match('/audit_log\([^;]*(employee_name|nome|login)/', $endpoint), 'endpoint: auditoria sem nome nem login');
$serviceSource = (string)file_get_contents($root . '/services/PaMapService.php');
assert_same([2, 1, 1], [
    substr_count($serviceSource, '$seat = $this->lockedSeat($paNumber);'),
    substr_count($serviceSource, "\$seat = \$this->seatSetupReady() ? \$this->lockedSeat(\$paNumber) : null;"),
    substr_count($serviceSource, "WHERE pa_number = :pa_number AND active = 1\n             LIMIT 1\n             FOR UPDATE'"),
], 'servico: inventario, preferencias e vinculo travam o PA (FOR UPDATE) antes de conferir');
assert_same(0, preg_match('/PaDistributionEngine::distribute/', $serviceSource), 'servico: o Mapa PA nao chama a distribuicao (so as funcoes do assento reservado)');

echo "QA PA inventory OK ({$GLOBALS['qa_checks']} verificacoes)\n";
