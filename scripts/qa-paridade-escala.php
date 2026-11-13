<?php
declare(strict_types=1);
// ============================================================================
// QA do script de paridade da escala mensal (scripts/paridade-escala-mensal.php,
// lote F10-1). Sem banco, sem rede, sem a planilha real: monta um xlsx
// sintetico com nomes ficticios num diretorio temporario e confere o leitor de
// xlsx, a leitura das abas com as regras do script, a traducao para regras do
// portal, os motivos da paridade da escala (com e sem portal), o modelo do
// casamento (so em _referencias/, sem sobrescrever), a entrada do motor de PA,
// a comparacao com a aba PA DIARIO e que a linha de comando so imprime
// contagens (nenhum nome).
// ============================================================================
define('PARIDADE_SEM_MAIN', true);
require_once __DIR__ . '/paridade-escala-mensal.php';

$root = dirname(__DIR__);
$checks = 0;
function assert_same($expected, $actual, string $message): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$message}\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function assert_error(callable $call, string $needle, string $message): void {
    try {
        $call();
    } catch (ParidadeErro $e) {
        assert_same(true, strpos($e->getMessage(), $needle) !== false, "{$message} (mensagem: {$e->getMessage()})");
        return;
    }
    assert_same('ParidadeErro', 'nenhuma excecao', $message);
}

$tmp = sys_get_temp_dir() . '/chronodesk-qa-paridade-' . bin2hex(random_bytes(6));
$ref = $tmp . '/_referencias';
if (!mkdir($ref, 0700, true)) {
    fwrite(STDERR, "FALHOU: nao criou o diretorio temporario\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp, $ref): void {
    foreach ([$ref, $tmp . '/outra'] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
    foreach (glob($tmp . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($tmp);
});

// ----------------------------------------------------------------------------
// Gerador de zip/xlsx sintetico (sem ZipArchive)
// ----------------------------------------------------------------------------
function qa_zip(string $path, array $entries): void {
    $data = '';
    $central = '';
    foreach ($entries as $name => $entry) {
        // [conteudo, metodo, tamanho declarado (opcional, para o caso corrompido)]
        [$content, $method, $declared] = $entry + [2 => null];
        $packed = $method === 8 ? gzdeflate($content) : $content;
        $crc = crc32($content);
        $size = $declared ?? strlen($content);
        $offset = strlen($data);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen($packed), $size, strlen($name), 0) . $name . $packed;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0, $crc, strlen($packed), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    file_put_contents($path, $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), strlen($data), 0));
}
function qa_col(int $n): string {
    $s = '';
    for (; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}
/** $sheets: [nome => [linha => [coluna => valor]]]; texto vai para sharedStrings, numero fica numero, '!x' vira inlineStr. */
function qa_xlsx(string $path, array $sheets): void {
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $shared = [];
    $entries = ['[Content_Types].xml' => ['<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', 8]];
    $workbook = '';
    $rels = '';
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        $xml = '';
        foreach ($rows as $r => $cols) {
            $xml .= '<row r="' . $r . '">';
            foreach ($cols as $c => $value) {
                $ref = qa_col($c) . $r;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } elseif (strpos($value, '!') === 0) {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars(substr($value, 1), ENT_XML1) . '</t></is></c>';
                } else {
                    $shared[$value] ??= count($shared);
                    $xml .= '<c r="' . $ref . '" t="s"><v>' . $shared[$value] . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $entries["xl/worksheets/sheet{$i}.xml"] = ['<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>' . $xml . '</sheetData></worksheet>', $i % 2 === 0 ? 0 : 8];
        $workbook .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . ($i === 1 ? '/xl/' : '') . 'worksheets/sheet' . $i . '.xml"/>';
    }
    // Texto com dois trechos formatados (rich text) num item do sharedStrings.
    $si = '';
    foreach (array_keys($shared) as $text) {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $half = intdiv(count($chars), 2);
        $si .= count($chars) > 3 && ctype_alpha($text[0]) ? '<si><r><t>' . htmlspecialchars(implode('', array_slice($chars, 0, $half)), ENT_XML1) . '</t></r><r><t xml:space="preserve">' . htmlspecialchars(implode('', array_slice($chars, $half)), ENT_XML1) . '</t></r></si>' : '<si><t>' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
    }
    $entries['xl/sharedStrings.xml'] = ['<?xml version="1.0"?><sst ' . $ns . '>' . $si . '</sst>', 8];
    $entries['xl/workbook.xml'] = ['<?xml version="1.0"?><workbook ' . $ns . '><sheets>' . $workbook . '</sheets></workbook>', 0];
    $entries['xl/_rels/workbook.xml.rels'] = ['<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>', 8];
    $entries['xl/media/image1.png'] = [str_repeat("\x89", 40), 0];
    qa_zip($path, $entries);
}
$serial = static fn (string $date): int => intdiv((int)strtotime($date . ' 00:00:00 UTC'), 86400) + 25569;

// ----------------------------------------------------------------------------
// Base sintetica (nomes ficticios)
// ----------------------------------------------------------------------------
$names = ['Ana Teste', 'Bruno Teste', 'Carla Teste', 'Davi Ficticio', 'Elisa Teste', 'Fabio Inativo', 'Gil Teste', 'Helena Teste', 'Ivo Antigo', 'Sempre Fora Equipe', 'Nome Fora'];
$history = [1 => [1 => 'ANO', 'MES', 'DATA', 'NOME', 'MODO', 'TIME', 'STATUS', 'TORRE']];
$status = [
    'Ana Teste' => ['RODIZIO', 'TIME 1', ['PRESENCIAL', 'FÉRIAS', 'PRESENCIAL', 'PRESENCIAL', 'HOME']],
    'Bruno Teste' => ['RODIZIO', 'TIME 2', ['PRESENCIAL', 'PRESENCIAL', 'HOME', 'HOME', 'PRESENCIAL']],
    'Carla Teste' => ['FIXO', '', ['PRESENCIAL', 'HOME', 'PRESENCIAL', 'PRESENCIAL', 'HOME']],
    'Davi Ficticio' => ['FIXO', '', ['HOME', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL']],
    'Helena Teste' => ['SEM HOME', '', ['PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'FÉRIAS']],
];
$octDays = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'];
foreach ($status as $name => [$mode, $team, $list]) {
    foreach ($octDays as $k => $date) {
        // Carla em 09/10 com o modo de antes (RODIZIO): "modo ou time mudou".
        $history[] = [1 => 2026, 10, $serial($date), $name, $name === 'Carla Teste' && $k === 4 ? 'RODIZIO' : $mode, $team, $list[$k], 'N1'];
    }
}
$history[] = [1 => 2026, 11, $serial('2026-11-02'), 'Ivo Antigo', 'RODIZIO', 'TIME 1', 'PRESENCIAL', 'N1'];
$history[] = [1 => 2026, 11, $serial('2026-11-02'), 'Elisa Teste', 'HIBRIDO', '', 'PRESENCIAL', 'HB'];
$history[] = [1 => 2026, 11, $serial('2026-11-02'), 'Gil Teste', 'RODIZIO', 'TIME 9', 'ATENÇÃO', 'N1'];
// Bruno com o time de antes (so o time mudou).
$history[] = [1 => 2026, 11, $serial('2026-11-02'), 'Bruno Teste', 'RODIZIO', 'TIME 3', 'HOME', 'N2'];
$base = [
    'CONFIG' => [4 => [2 => 10], 5 => [2 => 2026], 6 => [2 => '01/10/2026'], 7 => [2 => 12], 11 => [2 => 1], 12 => [2 => 2]],
    'EQUIPE' => [
        3 => [1 => 'ATIVO?', 'NOME'],
        4 => [1 => 'SIM', 'Ana Teste', 'N1', 'RODÍZIO', 'TIME 1', 'SIM'],
        5 => [1 => 'SIM', 'Bruno Teste', 'N2', 'RODIZIO', 'TIME 2', 'SIM'],
        6 => [1 => 'SIM', 'Carla Teste', 'N1', 'FIXO', '', 'SIM'],
        7 => [1 => 'SIM', '!Davi Ficticio', 'N2', 'FIXO', '', 'SIM'],
        8 => [1 => 'SIM', 'Elisa Teste', 'HB', 'HÍBRIDO', '', 'SIM'],
        9 => [1 => 'NAO', 'Fabio Inativo', 'N1', 'RODIZIO', 'TIME 1', 'SIM'],
        10 => [1 => 'SIM', 'Gil Teste', 'N1', 'RODIZIO', 'TIME 9', 'SIM'],
        11 => [1 => 'SIM', 'Helena Teste', 'N2', 'SEM HOME', '', 'SIM'],
    ],
    'REGRAS FIXAS' => [
        4 => [1 => 'SIM', 'Carla Teste', 'N1', 'SEMANAL', 1, 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'HOME', 13 => '30/09/2026'],
        5 => [1 => 'SIM', 'Davi Ficticio', 'N2', 'CICLO', 1, 'HOME', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL'],
        6 => [1 => 'SIM', 'davi ficticio', 'N2', 'CICLO', 2, 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'HOME'],
        7 => [1 => 'NAO', 'Helena Teste', 'N2', 'SEMANAL', 1, 'HOME', 'HOME', 'HOME', 'HOME', 'HOME'],
    ],
    'EXCECOES' => [
        4 => [1 => 'SIM', 'Ana Teste', $serial('2026-10-06'), '06/10/2026', 'FERIAS'],
        5 => [1 => 'SIM', 'Bruno Teste', $serial('2026-10-08'), $serial('2026-10-08'), 'VIAGEM'],
        6 => [1 => 'SIM', 'Carla Teste', '06/10/2026', '06/10/2026', 'HOME'],
    ],
    'CONFIG PA' => [
        4 => [1 => 1, 'SUPERIOR', 1, 'FLEXÍVEL', '', 'NAO', 'Ana Teste', 'SIM'],
        5 => [1 => 2, 'SUPERIOR', 2, 'FIXO', 'Helena Teste', 'NAO', '', 'SIM'],
        6 => [1 => 3, 'INFERIOR', 1, 'FIXO', 'Híbrido', 'NAO', '', 'SIM'],
        7 => [1 => 4, 'INFERIOR', 2, 'FLEXIVEL', '', 'NAO', 'Bruno Teste; Nome Fora', 'SIM'],
        8 => [1 => 5, 'INFERIOR', 3, 'BLOQUEADO', '', 'NAO', '', 'SIM'],
        9 => [1 => 6, 'INFERIOR', 4, 'FIXO', 'Sempre Fora Equipe', 'NAO', '', 'SIM'],
        10 => [1 => 7, 'SUPERIOR', 3, 'FLEXIVEL', '', 'NAO', '', 'NAO'],
        11 => [1 => 'x', 'SUPERIOR', 4, 'FLEXIVEL', '', 'NAO', '', 'SIM'],
    ],
    'HISTORICO' => $history,
];
$basePath = $tmp . '/base.xlsx';
qa_xlsx($basePath, $base);

// ----------------------------------------------------------------------------
// 1. Leitor de xlsx e conversoes
// ----------------------------------------------------------------------------
$sheets = paridade_xlsx_sheets($basePath);
assert_same(['CONFIG', 'EQUIPE', 'REGRAS FIXAS', 'EXCECOES', 'CONFIG PA', 'HISTORICO'], array_keys($sheets), 'leitor: abas pelo nome, na ordem do workbook (alvo com e sem /xl/)');
assert_same(['Ana Teste', 'Davi Ficticio', '01/10/2026', '2026'], [$sheets['EQUIPE'][4][2], $sheets['EQUIPE'][7][2], $sheets['CONFIG'][6][2], $sheets['CONFIG'][5][2]], 'leitor: texto compartilhado com trechos (rich text), inlineStr e numero');
assert_same(['2026-10-01', '2025-01-01', '2026-10-01', '2026-10-01', null, null], [paridade_date('46296'), paridade_date('45658.75'), paridade_date('01/10/2026'), paridade_date('2026-10-01'), paridade_date('31/02/2026'), paridade_date('')], 'datas: serial do Excel, dd/mm/aaaa, aaaa-mm-dd; invalida vira null');
assert_same(['2026-10-01'], [paridade_date('01/10/26')], 'datas: dd/mm/aa');
assert_same([7, 1, 1, 2], [paridade_int_or('7', 1), paridade_int_or('0', 1), paridade_int_or('', 1), paridade_int_or('2.0', 1)], 'int(valor or padrao) do Python: zero e vazio viram o padrao');
assert_same(28, paridade_column('AB'), 'coluna AB = 28');
qa_zip($tmp . '/doctype.xlsx', ['xl/workbook.xml' => ['<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>', 0], 'xl/_rels/workbook.xml.rels' => ['<x/>', 0]]);
assert_error(static fn () => paridade_xlsx_sheets($tmp . '/doctype.xlsx'), 'DOCTYPE', 'xml com DOCTYPE (XXE) recusado');
qa_zip($tmp . '/caso12.xlsx', ['xl/workbook.xml' => ['<x/>', 12]]);
assert_error(static fn () => paridade_zip_entries($tmp . '/caso12.xlsx'), 'metodo de compressao 12', 'compressao desconhecida recusada');
qa_zip($tmp . '/tamanho.xlsx', ['xl/workbook.xml' => ['<x/>', 0, 99]]);
assert_error(static fn () => paridade_zip_entries($tmp . '/tamanho.xlsx'), 'corrompida', 'entrada guardada com tamanho declarado diferente recusada');
$many = (string)file_get_contents($tmp . '/caso12.xlsx');
$eocdAt = strrpos($many, "PK");
file_put_contents($tmp . '/entradas.xlsx', substr_replace($many, pack('vv', 2001, 2001), $eocdAt + 8, 4));
assert_error(static fn () => paridade_zip_entries($tmp . '/entradas.xlsx'), 'fora do limite', 'zip com mais de 2000 entradas recusado antes de ler');
file_put_contents($tmp . '/lixo.xlsx', 'nao e zip');
assert_error(static fn () => paridade_zip_entries($tmp . '/lixo.xlsx'), 'zip invalido', 'arquivo que nao e zip');
assert_error(static fn () => paridade_zip_entries($tmp . '/nao-existe.xlsx'), 'inexistente', 'arquivo inexistente');
$broken = (string)file_get_contents($basePath);
file_put_contents($tmp . '/corrompido.xlsx', substr_replace($broken, 'XXXX', 60, 4));
assert_error(static fn () => paridade_zip_entries($tmp . '/corrompido.xlsx'), 'corrompida', 'entrada corrompida (tamanho diferente) recusada');
qa_zip($tmp . '/sem-aba.xlsx', ['xl/workbook.xml' => ['<x/>', 0]]);
assert_error(static fn () => paridade_xlsx_sheets($tmp . '/sem-aba.xlsx'), 'workbook.xml.rels', 'xlsx sem as relacoes');
assert_error(static fn () => paridade_base(['CONFIG' => []]), 'EQUIPE', 'planilha sem as abas de entrada');

// ----------------------------------------------------------------------------
// 2. Abas de entrada, com as regras do script
// ----------------------------------------------------------------------------
$b = paridade_base($sheets);
assert_same(['anchor' => '2026-10-01', 'phase' => ['TIME 1' => 0, 'TIME 2' => 1, 'TIME 3' => 2]], $b['config'], 'CONFIG: ancora em texto e fases (vazia vira o padrao)');
assert_same(['ANA TESTE', 'RODIZIO', 'TIME 1', true, true], [$b['team'][0]['key'], $b['team'][0]['mode'], $b['team'][0]['team'], $b['team'][0]['active'], $b['team'][0]['common']], 'EQUIPE: chave e modo normalizados (acento fora)');
assert_same(['ANA TESTE', 'BRUNO TESTE', 'CARLA TESTE', 'DAVI FICTICIO', 'ELISA TESTE', 'GIL TESTE', 'HELENA TESTE'], array_keys(paridade_active_team($b)), 'EQUIPE ativa: o inativo fica de fora');
assert_same([[1, 2], 'CICLO', '2026-09-30'], [array_keys($b['rules']['DAVI FICTICIO']['cycles']), $b['rules']['DAVI FICTICIO']['type'], $b['fixed_anchor']], 'REGRAS FIXAS: semanas pela chave normalizada, ancora em M4; linha inativa ignorada');
assert_same(false, isset($b['rules']['HELENA TESTE']), 'REGRAS FIXAS: linha com ATIVO NAO ignorada');
assert_same([['key' => 'ANA TESTE', 'start' => '2026-10-06', 'end' => '2026-10-06', 'status' => 'FERIAS'], ['key' => 'CARLA TESTE', 'start' => '2026-10-06', 'end' => '2026-10-06', 'status' => 'HOME']], $b['exceptions'], 'EXCECOES: so os status do script (VIAGEM fora), datas em serial ou texto');
assert_same([[1, 'FLEXIVEL', ['Ana Teste']], [4, 'FLEXIVEL', ['Bruno Teste', 'Nome Fora']], 6], [[$b['pas'][0]['pa'], $b['pas'][0]['type'], $b['pas'][0]['prefs']], [$b['pas'][3]['pa'], $b['pas'][3]['type'], $b['pas'][3]['prefs']], count($b['pas'])], 'CONFIG PA: preferencias separadas por ;, linha inativa e PA nao numerico fora');
assert_same([29, '2026-10-05', 'ANA TESTE', 'FERIAS'], [count($b['history']), $b['history'][0]['date'], $b['history'][0]['key'], $b['history'][1]['status']], 'HISTORICO: data em serial, status normalizado');

// Traducao para regras do portal (desenho 2.2).
$team = paridade_active_team($b);
assert_same(['cycle', ['remote', 'onsite', 'onsite'], '2026-10-01'], [paridade_portal_rule($b, $team['ANA TESTE'])['rule_type'], paridade_portal_rule($b, $team['ANA TESTE'])['rule_config']['pattern'], paridade_portal_rule($b, $team['ANA TESTE'])['rule_config']['anchor_date']], 'RODIZIO Time 1: ciclo [remoto, presencial, presencial] com a ancora da CONFIG');
assert_same(['onsite', 'remote', 'onsite'], paridade_portal_rule($b, $team['BRUNO TESTE'])['rule_config']['pattern'], 'RODIZIO Time 2: fase 1');
assert_same(['fixed_weekdays', ['weekdays' => ['mon', 'tue', 'wed', 'thu']]], [paridade_portal_rule($b, $team['CARLA TESTE'])['rule_type'], paridade_portal_rule($b, $team['CARLA TESTE'])['rule_config']], 'FIXO SEMANAL: dias presenciais');
$davi = paridade_portal_rule($b, $team['DAVI FICTICIO']);
assert_same(['cycle', 10, '2026-10-05', 'remote', 'remote'], [$davi['rule_type'], count($davi['rule_config']['pattern']), $davi['rule_config']['anchor_date'], $davi['rule_config']['pattern'][0], $davi['rule_config']['pattern'][9]], 'FIXO CICLO de 2 semanas: 10 posicoes, ancora numa quarta vira a segunda seguinte');
assert_same(['always_onsite', null], [paridade_portal_rule($b, $team['HELENA TESTE'])['rule_type'], paridade_portal_rule($b, $team['GIL TESTE'])], 'SEM HOME = always_onsite; time fora de TIME 1..3 sem traducao');
$semRegra = $team['CARLA TESTE'];
$semRegra['key'] = 'OUTRA PESSOA';
assert_same('always_onsite', paridade_portal_rule($b, $semRegra)['rule_type'], 'FIXO sem regra = presencial (script, linha 169)');
$gap = $b;
$gap['rules']['DAVI FICTICIO']['cycles'] = [1 => [0 => 'HOME', 1 => 'PRESENCIAL', 2 => 'PRESENCIAL', 3 => 'PRESENCIAL', 4 => 'PRESENCIAL'], 3 => [0 => 'PRESENCIAL', 1 => 'PRESENCIAL', 2 => 'HOME', 3 => 'PRESENCIAL', 4 => 'PRESENCIAL']];
$gapPattern = paridade_portal_rule($gap, $team['DAVI FICTICIO'])['rule_config']['pattern'];
assert_same([15, array_slice($gapPattern, 0, 5), 'remote'], [count($gapPattern), array_slice($gapPattern, 5, 5), $gapPattern[12]], 'FIXO CICLO com semana sem linha: usa a semana 1 (script, linha 176)');
$monday = $b;
$monday['fixed_anchor'] = '2026-10-05';
assert_same('2026-10-05', paridade_portal_rule($monday, $team['DAVI FICTICIO'])['rule_config']['anchor_date'], 'FIXO CICLO com ancora na segunda: a propria');

// ----------------------------------------------------------------------------
// 3. Paridade da escala sem portal (configuracao atual x HISTORICO)
// ----------------------------------------------------------------------------
$detail = [];
$counts = paridade_scale_from_sheet($b, ['2026-10', '2026-11'], $detail);
assert_same([
    '2026-10' => ['linhas' => 25, 'iguais' => 22, 'mesma regra, dia diferente' => 1, 'modo ou time mudou depois da geracao' => 1, 'excecao ou ausencia so de um lado' => 1],
    '2026-11' => ['linhas' => 4, 'modo ou time mudou depois da geracao' => 1, 'fora da EQUIPE atual' => 1, 'fora do grupo comum hoje' => 1, 'sem regra traduzivel' => 1],
], array_map(static function (array $c): array { $order = ['linhas', 'iguais', 'mesma regra, dia diferente', 'modo ou time mudou depois da geracao', 'excecao ou ausencia so de um lado', 'fora da EQUIPE atual', 'fora do grupo comum hoje', 'sem regra traduzivel']; return array_filter(array_replace(array_fill_keys($order, null), $c), static fn ($v): bool => $v !== null); }, $counts), 'escala sem portal: cada motivo na contagem');
assert_same(['BRUNO TESTE', '2026-10-08', 'mesma regra, dia diferente'], $detail[0], 'detalhe: chave, data e motivo (so para o --detalhe)');
assert_same([], paridade_scale_from_sheet($b, ['2027-01']), 'mes sem HISTORICO: nenhuma contagem');

// ----------------------------------------------------------------------------
// 4. Casamento: modelo, gravacao e leitura
// ----------------------------------------------------------------------------
$rows = paridade_template_rows($b);
$byKey = array_column($rows, null, 1);
assert_same(['ANA TESTE', 'BRUNO TESTE', 'CARLA TESTE', 'DAVI FICTICIO', 'ELISA TESTE', 'FABIO INATIVO', 'GIL TESTE', 'HELENA TESTE', 'IVO ANTIGO', 'NOME FORA', 'SEMPRE FORA EQUIPE'], array_keys($byKey), 'modelo: um nome normalizado por linha, sem Hibrido/Critico, de todas as abas');
assert_same(['Davi Ficticio', 'DAVI FICTICIO', 'Davi', 'NAO', '', 'EQUIPE+HISTORICO+REGRAS FIXAS'], $byKey['DAVI FICTICIO'], 'modelo: primeiro nome, ambiguo, ID vazio e abas');
assert_same(['HISTORICO', 'CONFIG PA', 'CONFIG PA'], [$byKey['IVO ANTIGO'][5], $byKey['SEMPRE FORA EQUIPE'][5], $byKey['NOME FORA'][5]], 'modelo: nomes so no HISTORICO e so no CONFIG PA');
$withHomonym = $b;
$withHomonym['team'][] = ['name' => 'Ana Outra', 'key' => 'ANA OUTRA', 'active' => true, 'tower' => '', 'mode' => '', 'team' => '', 'common' => false];
assert_same(['SIM', 'SIM', 'NAO'], array_map(static fn (array $r): string => $r[3], array_slice(paridade_template_rows($withHomonym), 0, 3)), 'modelo: primeiro nome repetido marcado como ambiguo');
mkdir($tmp . '/outra');
assert_error(static fn () => paridade_write_template($tmp . '/outra/casamento.csv', $rows), '_referencias', 'modelo: so grava numa pasta _referencias');
paridade_write_template($ref . '/casamento.csv', $rows);
assert_error(static fn () => paridade_write_template($ref . '/casamento.csv', []), 'ja existe', 'modelo: nunca sobrescreve o arquivo (pode estar preenchido)');
$csv = (string)file_get_contents($ref . '/casamento.csv');
assert_same([true, 12], [strpos($csv, "\xEF\xBB\xBFnome_planilha;nome_normalizado;primeiro_nome_pa_diario;ambiguo;employee_id;abas\n") === 0, substr_count($csv, "\n")], 'modelo: BOM, cabecalho e uma linha por nome');
$match = paridade_read_match($ref . '/casamento.csv');
assert_same([null, 'DAVI', false], [$match['DAVI FICTICIO']['id'], $match['DAVI FICTICIO']['first'], $match['DAVI FICTICIO']['ambiguous']], 'casamento: le o modelo (ID vazio = sem casamento)');
$fill = ['ANA TESTE' => [11, null, null], 'BRUNO TESTE' => [12, null, 'SIM'], 'CARLA TESTE' => [13, null, null], 'DAVI FICTICIO' => [14, 'Davi F.', null], 'HELENA TESTE' => [15, null, null], 'SEMPRE FORA EQUIPE' => [90, null, null]];
$filledRows = [PARIDADE_TEMPLATE_HEADER];
foreach ($rows as $row) {
    [$id, $first, $ambiguous] = $fill[$row[1]] ?? [null, null, null];
    $filledRows[] = [$row[0], $row[1], $first ?? $row[2], $ambiguous ?? $row[3], $id === null ? '' : (string)$id, $row[5]];
}
$handle = fopen('php://memory', 'w+b');
foreach ($filledRows as $row) {
    fputcsv($handle, $row, ';', '"', '');
}
rewind($handle);
$filled = "ï»¿" . stream_get_contents($handle);
fclose($handle);
file_put_contents($ref . '/preenchido.csv', $filled);
$match = paridade_read_match($ref . '/preenchido.csv');
assert_same([11, 'DAVI F.', true, null], [$match['ANA TESTE']['id'], $match['DAVI FICTICIO']['first'], $match['BRUNO TESTE']['ambiguous'], $match['GIL TESTE']['id']], 'casamento preenchido: ID, primeiro nome e ambiguo');
file_put_contents($ref . '/ruim.csv', str_replace(';11;', ';11a;', $filled));
assert_same(1, substr_count($filled, ';11;'), 'QA: o casamento preenchido tem o ID 11 uma vez');
assert_error(static fn () => paridade_read_match($ref . '/ruim.csv'), 'employee_id invalido', 'casamento: ID que nao e inteiro positivo recusado');
file_put_contents($ref . '/sem-coluna.csv', "nome;id\n");
assert_error(static fn () => paridade_read_match($ref . '/sem-coluna.csv'), 'sem a coluna', 'casamento sem as colunas');

// ----------------------------------------------------------------------------
// 5. Paridade da escala com o portal (JSON paridade-escala-v1)
// ----------------------------------------------------------------------------
$portalLines = [];
foreach ($octDays as $k => $date) {
    $portalLines[] = ['employee_id' => 11, 'data' => $date, 'situacao' => ['presencial', 'ausente', 'presencial', 'presencial', 'remoto'][$k], 'fonte' => $k === 1 ? 'ausencia' : 'regra', 'rule_type' => 'cycle'];
    // 07/10: HOME na planilha e presencial no portal com a mesma regra; 08/10: tipo diferente.
    $portalLines[] = ['employee_id' => 12, 'data' => $date, 'situacao' => 'presencial', 'fonte' => 'regra', 'rule_type' => $k === 3 ? 'fixed_weekdays' : 'cycle'];
    $portalLines[] = ['employee_id' => 13, 'data' => $date, 'situacao' => $k === 0 ? 'sem_escala' : ($k === 4 || $k === 1 ? 'remoto' : 'presencial'), 'fonte' => $k === 0 ? 'nenhuma' : ($k === 1 ? 'excecao' : 'regra'), 'rule_type' => 'fixed_weekdays'];
    $portalLines[] = ['employee_id' => 14, 'data' => $date, 'situacao' => $k === 0 ? 'fora' : 'presencial', 'fonte' => 'regra', 'rule_type' => 'cycle'];
}
$portalLines[] = ['employee_id' => 15, 'data' => '2026-10-05', 'situacao' => 'dia_nao_util', 'fonte' => 'nenhuma', 'rule_type' => null];
$portalLines[] = ['employee_id' => 77, 'data' => '2026-10-05', 'situacao' => 'presencial', 'fonte' => 'regra', 'rule_type' => 'cycle'];
$portalLines[] = ['employee_id' => 78, 'data' => '2026-10-05', 'situacao' => 'sem_escala', 'fonte' => 'nenhuma', 'rule_type' => null];
file_put_contents($tmp . '/portal.json', json_encode(['formato' => 'paridade-escala-v1', 'mes' => '2026-10', 'linhas' => $portalLines]));
$portal = paridade_read_portal([$tmp . '/portal.json']);
$detail = [];
$counts = paridade_scale_from_portal($b, ['2026-10'], $match, $portal, $detail)['2026-10'];
ksort($counts);
assert_same([
    'dia nao util de um lado' => 1,
    'fora do grupo comum de um lado (portal: fora do Mapa)' => 1,
    'fora do grupo comum de um lado (so na planilha)' => 4,
    'fora do grupo comum de um lado (so no portal)' => 1,
    'iguais' => 16,
    'linhas' => 25,
    'mesma regra, dia diferente' => 1,
    'sem regra no portal' => 1,
    'tipo de regra diferente' => 1,
], $counts, 'escala com portal: cada motivo da secao 4.1');
assert_same([12, '2026-10-07', 'mesma regra, dia diferente'], $detail[0], 'detalhe com portal: so o employee_id, a data e o motivo');
$noMatch = $match;
$noMatch['ANA TESTE']['id'] = null;
assert_same(5, paridade_scale_from_portal($b, ['2026-10'], $noMatch, $portal)['2026-10']['sem casamento de ID'], 'escala com portal: sem ID no casamento');
file_put_contents($tmp . '/portal-ruim.json', json_encode(['formato' => 'outro', 'linhas' => []]));
assert_error(static fn () => paridade_read_portal([$tmp . '/portal-ruim.json']), 'paridade-escala-v1', 'JSON do portal em outro formato');
file_put_contents($tmp . '/portal-ruim.json', json_encode(['formato' => 'paridade-escala-v1', 'linhas' => [['employee_id' => '11', 'data' => '2026-10-05']]]));
assert_error(static fn () => paridade_read_portal([$tmp . '/portal-ruim.json']), 'employee_id', 'JSON do portal com ID em texto');

// ----------------------------------------------------------------------------
// 6. Paridade de PA: entrada do motor, contagens e PA DIARIO
// ----------------------------------------------------------------------------
$built = paridade_pa_input($b, '2026-10', $match, 'presente');
$input = $built['input'];
assert_same($octDays, $input['days'], 'PA: dias do HISTORICO do mes');
assert_same([11 => 'ANA TESTE', 12 => 'BRUNO TESTE', 13 => 'CARLA TESTE', 14 => 'DAVI FICTICIO', 15 => 'HELENA TESTE'], $input['people'], 'PA: grupo comum do HISTORICO pelo ID do casamento');
assert_same(['onsite', 'absent', 'remote'], [$input['presence']['2026-10-05'][11], $input['presence']['2026-10-06'][11], $input['presence']['2026-10-09'][11]], 'PA: PRESENCIAL/FERIAS/HOME viram onsite/absent/remote');
$seatsByPa = array_column($input['seats'], null, 'pa');
assert_same([[1, 2, 3, 4, 5, 6], 'flexible', 'fixed', 'hybrid', true, [['employee_id' => 15]], [1, 1], [2, 4]], [array_keys($seatsByPa), $seatsByPa[1]['type'], $seatsByPa[2]['type'], $seatsByPa[3]['reserved_for'], $seatsByPa[5]['unavailable'], $seatsByPa[2]['owners'], [$seatsByPa[1]['row'], $seatsByPa[1]['column']], [$seatsByPa[6]['row'], $seatsByPa[6]['column']]], 'PA: CONFIG PA traduzido (tipo, reserva, bloqueado, dono, fileira e ordem)');
assert_same([[11], 12, true], [$input['preferences'][1], $input['preferences'][4][0], $input['preferences'][4][1] >= 1000000000], 'PA: preferencias pelo casamento; nome sem ID ganha ID provisorio acima dos reais');
assert_same(['onsite', 'absent'], [$input['presence']['2026-10-05'][90], paridade_pa_input($b, '2026-10', $match, 'ausente')['input']['presence']['2026-10-05'][90]], 'PA: dono fora da EQUIPE presente ou ausente pela opcao');
assert_same(['pessoas' => 5, 'donos de fora do grupo comum' => 1, 'donos fora da EQUIPE (presente)' => 1, 'sem casamento de ID' => 1], $built['counts'], 'PA: contagens da entrada');
$noMatchBuilt = paridade_pa_input($b, '2026-10', null, 'presente');
assert_same([0, true], [$noMatchBuilt['counts']['sem casamento de ID'], min(array_keys($noMatchBuilt['input']['people'])) >= 1000000000], 'PA sem casamento: IDs provisorios em memoria');
$result = PaDistributionEngine::distribute($input);
$paCounts = paridade_pa_counts($result);
assert_same([5, 5, 0], [$paCounts['dias'], $paCounts['programacao dinamica'] + $paCounts['guloso'], $paCounts['ninguem presencial']], 'PA: contagens do motor');
assert_same('2-2', $paCounts['PAs livres no dia (min-max)'], 'PA: PAs livres no dia (flexiveis 1 e 4)');
assert_same([90, 15, 'hybrid'], [$result['days']['2026-10-05']['occupants'][6]['employee_id'], $result['days']['2026-10-05']['occupants'][2]['employee_id'], $result['days']['2026-10-05']['occupants'][3]['reserved_for']], 'PA: dono fora da EQUIPE, dono do grupo comum e reservado');

// Aba PA DIARIO sintetica a partir do proprio resultado (como o script escreve).
$keys = $built['keys'];
$shortOf = static function (?array $occ) use ($keys, $match): string {
    if ($occ === null) {
        return 'LIVRE';
    }
    if ($occ['employee_id'] === null) {
        return $occ['reserved_for'] === 'hybrid' ? 'Híbrido' : 'Crítico';
    }
    $key = $keys[$occ['employee_id']];
    return ($match[$key]['first'] ?? '') !== '' ? ucwords(strtolower($match[$key]['first'])) : ucfirst(strtolower(explode(' ', $key)[0]));
};
$daily = [1 => [1 => 'PA DIÁRIO - 10/2026'], 3 => [1 => 'PA', 'TIPO', 'REFERÊNCIA']];
foreach ($octDays as $k => $date) {
    $daily[3][4 + $k] = substr($date, 8, 2) . '/' . substr($date, 5, 2);
}
$r = 4;
foreach ([1, 2, 3, 4, 5, 6] as $pa) {
    $daily[$r] = [1 => $pa, 'FLEX', ''];
    foreach ($octDays as $k => $date) {
        $daily[$r][4 + $k] = $shortOf($result['days'][$date]['occupants'][$pa] ?? null);
    }
    $r++;
}
$daily[$r + 1] = [1 => 'TROCAS FLEXÍVEIS'];
$daily[$r + 2] = [1 => 'SEM PA'];
foreach ($octDays as $k => $date) {
    $daily[$r + 1][4 + $k] = $result['days'][$date]['moves'];
    $noPa = array_map(static fn (int $id): string => $shortOf(['employee_id' => $id, 'reserved_for' => null]), $result['days'][$date]['no_pa']);
    $daily[$r + 2][4 + $k] = $noPa === [] ? '-' : implode(', ', $noPa);
}
qa_xlsx($tmp . '/mensal.xlsx', ['ESCALA 10-2026' => [1 => [1 => 'x']], 'PA DIARIO 10-2026' => $daily]);
$seatNumbers = array_column($input['seats'], 'pa');
$cmp = paridade_pa_compare(paridade_xlsx_sheets($tmp . '/mensal.xlsx'), '2026-10', $result, $keys, $match, $seatNumbers);
$ambiguousCells = 0;
foreach ($octDays as $date) {
    foreach ($result['days'][$date]['occupants'] as $occ) {
        $ambiguousCells += ($occ['employee_id'] !== null && ($match[$keys[$occ['employee_id']]]['ambiguous'] ?? false)) ? 1 : 0;
    }
}
assert_same(true, $ambiguousCells > 0, 'PA DIARIO: a amostra tem celula de primeiro nome ambiguo');
assert_same(['dias na aba' => 5, 'dias sem a data no motor' => 0, 'celulas iguais' => 30 - $ambiguousCells, 'celulas divergentes' => 0, 'celulas com primeiro nome ambiguo' => $ambiguousCells, 'PAs so na aba' => 0, 'trocas iguais (dias)' => 5, 'trocas divergentes (dias)' => 0, 'sem PA iguais (dias)' => 5, 'sem PA divergentes (dias)' => 0], $cmp, 'PA DIARIO gerado do mesmo resultado: tudo igual, ambiguo a parte, LIVRE e reservado reconhecidos');
// Divergencias: um ocupante, uma troca, um sem PA, um PA a mais e um dia que o motor nao tem.
$changed = $daily;
$freeRow = null;
foreach ([4, 5, 6, 7, 8, 9] as $row) {
    if ($changed[$row][4] === 'LIVRE') {
        $freeRow = $row;
    }
}
$changed[$freeRow][4] = 'Fulano';
$changed[$r + 1][5] = 99;
$changed[$r + 2][6] = 'Alguem';
$changed[$r + 3] = [];
$changed[3][9] = '12/10';
$changed[$r] = [1 => 99, 'FLEX', ''];
qa_xlsx($tmp . '/mensal2.xlsx', ['PA DIARIO 10-2026' => $changed]);
$cmp = paridade_pa_compare(paridade_xlsx_sheets($tmp . '/mensal2.xlsx'), '2026-10', $result, $keys, $match, $seatNumbers);
assert_same([6, 1, 1, 1, 1, 1], [$cmp['dias na aba'], $cmp['dias sem a data no motor'], $cmp['celulas divergentes'] - 5, $cmp['trocas divergentes (dias)'], $cmp['sem PA divergentes (dias)'], $cmp['PAs so na aba']], 'PA DIARIO: cada divergencia contada (o PA 99 so na aba diverge nos 5 dias)');
assert_error(static fn () => paridade_pa_compare(['OUTRA' => []], '2026-10', $result, $keys, $match, $seatNumbers), 'PA DIARIO 10-2026', 'arquivo mensal sem a aba do mes');

// ----------------------------------------------------------------------------
// 7. Linha de comando: so contagens, nenhum nome
// ----------------------------------------------------------------------------
$php = PHP_BINARY;
$script = $root . '/scripts/paridade-escala-mensal.php';
$run = static function (array $args) use ($php, $script): array {
    $out = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
$forbidden = '/\b(ana|bruno|carla|davi|elisa|fabio|gil|helena|ivo|sempre|fulano|alguem|teste|ficticio|inativo|antigo)\b/i';
[$rc, $out] = $run(['escala', $basePath, '--meses=2026-10,2026-11', '--detalhe']);
assert_same([0, 0, true, true], [$rc, preg_match($forbidden, $out), strpos($out, 'iguais: 22') !== false, strpos($out, '#1 (sem ID) 2026-10-08: mesma regra, dia diferente') !== false], 'CLI escala sem portal: contagens e detalhe por indice, sem nome');
[$rc, $out] = $run(['escala', $basePath, '--meses=2026-10', '--casamento=' . $ref . '/preenchido.csv', '--portal=' . $tmp . '/portal.json', '--detalhe']);
assert_same([0, 0, true, true], [$rc, preg_match($forbidden, $out), strpos($out, 'tipo de regra diferente: 1') !== false, strpos($out, 'ID 12 2026-10-08') !== false], 'CLI escala com portal: contagens e IDs, sem nome');
[$rc, $out] = $run(['pa', $basePath, '--mes=2026-10', '--casamento=' . $ref . '/preenchido.csv', '--mensal=' . $tmp . '/mensal.xlsx']);
assert_same([0, 0, true], [$rc, preg_match($forbidden, $out), strpos($out, 'celulas divergentes: 0') !== false], 'CLI pa: contagens, sem nome');
[$rc, $out] = $run(['modelo-casamento', $basePath, $ref . '/cli.csv']);
assert_same([0, 0, true, true], [$rc, preg_match($forbidden, $out), strpos($out, 'nomes: 11') !== false, is_file($ref . '/cli.csv')], 'CLI modelo-casamento: grava e imprime so contagens');
foreach ([
    [['escala', $basePath, '--meses=2026-10', '--portal=' . $tmp . '/portal.json'], 'exige --casamento'],
    [['escala', $basePath], '--meses'],
    [['pa', $basePath, '--mes=2026-13x'], '--mes'],
    [['pa', $basePath, '--mes=2027-01'], 'nao tem 2027-01'],
    [['pa', $basePath, '--mes=2026-10', '--fora-da-equipe=talvez'], 'presente ou ausente'],
    [['modelo-casamento', $basePath], 'falta o caminho'],
    [['outro', $basePath], 'uso:'],
    [['escala', $tmp . '/nao-existe.xlsx', '--meses=2026-10'], 'inexistente'],
] as [$args, $needle]) {
    [$rc, $out] = $run($args);
    assert_same([2, true], [$rc, strpos($out, $needle) !== false], 'CLI recusa com saida 2: ' . $needle);
}
$source = (string)file_get_contents($script);
$code = implode('', array_map(static fn ($t): string => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t), token_get_all($source)));
assert_same([true, true, 0], [strpos($code, "if (PHP_SAPI !== 'cli') {") !== false, strpos($code, "require_once __DIR__ . '/../services/PaDistributionEngine.php';") !== false, preg_match('/\b(PDO|mysqli|curl_|fsockopen|ZipArchive|file_put_contents|unlink)\b/', $code)], 'script: so linha de comando, sem banco, rede, ZipArchive nem gravacao fora do modelo');

echo "QA paridade escala OK ({$checks} verificacoes)\n";
