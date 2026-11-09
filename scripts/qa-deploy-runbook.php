<?php
declare(strict_types=1);

// ============================================================================
// Travas do roteiro unico do deploy 1 da Parte B (DEPLOY_LINUX.md, desenho
// 9.13; decisao de 2026-11-06). Le so o documento e a arvore do repositorio:
// sem banco, sem rede, sem executar comando do roteiro.
//
// O que fica travado: um comando por linha; sem pull; um fetch so, antes de
// gravar o commit; ordem gravar -> cat-file -> conferencia de fast-forward ->
// archive com pipefail -> ensaio -> merge --ff-only do commit gravado; so
// contagens; senha sem eco e com unset; as migrations do deploy 1 (011, 014,
// 019 e 012, esta junto com o merge), cada uma com ensaio e verificacao, e
// nenhuma do F8 nem do F9; o ensaio de codigo com todos os QAs PHP e a
// conferencia do /tmp/.env; o aviso a gestao com marcadores; o commit 1 do F8
// sem chamada fora do QA dele; o push do branch codex so no passo 9a, sem
// --force (NN10); o .env.production.example sem HTTPS forcado; a folha do dia
// (docs/DEPLOY1_FOLHA_DO_DIA.md) com os mesmos comandos, na mesma ordem.
// ============================================================================

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

$deployDoc = str_replace("\r\n", "\n", (string)file_get_contents($root . '/DEPLOY_LINUX.md'));
$heading = '### Deploy 1 da Parte B (roteiro unico';
$start = strpos($deployDoc, $heading);
$ends = $start === false ? [] : array_filter([strpos($deployDoc, "\n### ", $start + 1), strpos($deployDoc, "\n## ", $start)], static fn ($pos): bool => $pos !== false);
$runbook = $start === false ? '' : substr($deployDoc, $start, ($ends === [] ? strlen($deployDoc) : min($ends)) - $start);
$prose = (string)preg_replace('/\s+/', ' ', $runbook);
preg_match_all('/```bash\n(.*?)```/s', $runbook, $bashBlocks);
preg_match_all('/```powershell\n(.*?)```/s', $runbook, $psBlocks);
$commands = array_values(array_filter(explode("\n", implode("\n", $bashBlocks[1])), static fn (string $line): bool => trim($line) !== ''));
$psCommands = array_values(array_filter(explode("\n", implode("\n", $psBlocks[1])), static fn (string $line): bool => trim($line) !== ''));
$text = implode("\n", $commands);
$first = static function (string $needle) use ($commands): int {
    foreach ($commands as $i => $line) {
        if (strpos($line, $needle) !== false) {
            return $i;
        }
    }
    return -1;
};
$all = static function (string $needle) use ($commands): array {
    return array_keys(array_filter($commands, static fn (string $line): bool => strpos($line, $needle) !== false));
};

// ----------------------------------------------------------------------------
// 1. Secao, uma linha por comando, A DEFINIR
// ----------------------------------------------------------------------------
assert_same(true, $runbook !== '' && count($commands) >= 50 && count($psCommands) >= 2, 'roteiro unico: secao, comandos Linux e da estacao presentes');
assert_same([], array_values(array_filter(array_merge($commands, $psCommands), static fn (string $line): bool => substr(rtrim($line), -1) === '\\' || substr(rtrim($line), -1) === '`')), 'roteiro unico: cada comando em uma linha (sem continuacao)');
assert_same([true, true], [strpos($prose, '**Data e janela do deploy**, fora do horario de operacao: A DEFINIR.') !== false, strpos($prose, '**Quem acompanha** (alem de quem executa): A DEFINIR.') !== false], 'roteiro unico: data e janela e quem acompanha marcados A DEFINIR');
$stepOrder = array_map(static fn (string $step): int => (int)strpos($runbook, $step), ["\n0a. ", "\n0l. ", "\n**1. ", "\n**2. ", "\n**3. ", "\n**4. ", "\n**5. ", "\n**6. ", "\n**7. ", "\n**8. ", "\n**9. "]);
$sortedSteps = $stepOrder;
sort($sortedSteps);
assert_same([true, $sortedSteps], [!in_array(0, $stepOrder, true), $stepOrder], 'roteiro unico: passos 0 a 9 presentes e na ordem pedida');

// ----------------------------------------------------------------------------
// 2. Branch, um fetch so, commit gravado, cat-file, fast-forward, sem pull
// ----------------------------------------------------------------------------
$recordLine = $first('| sudo tee /var/backups/chronodesk/deploy1-commit');
$catFileLine = $first('sudo git cat-file -e "$D1^{commit}"');
$ancestorLine = $first('sudo git merge-base --is-ancestor HEAD "$D1"');
$mergeLines = $all('git merge');
$mergeLine = $first('&& sudo git merge --ff-only "$D1"; unset P');
$fetchLines = array_keys(array_filter($commands, static fn (string $line): bool => preg_match('/\bgit fetch\b/', $line) === 1));
assert_same(0, preg_match('/\bgit pull\b/', $text . "\n" . implode("\n", $psCommands)), 'roteiro unico: sem git pull');
assert_same([$mergeLine], array_values(array_filter($mergeLines, static fn (int $i): bool => strpos($commands[$i], 'merge-base') === false)), 'roteiro unico: um merge so, o --ff-only do commit gravado');
assert_same(true, $recordLine >= 0 && $fetchLines !== [] && max($fetchLines) <= $recordLine, 'roteiro unico: todo fetch antes de gravar o commit (nenhum depois)');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; sudo git fetch origin feat\/employee-form-rhf-zod && K=\$\(sudo git rev-parse origin\/feat\/employee-form-rhf-zod\) && if \[ "\$K" = "<commit validado na estacao>" \]; then echo "\$K" \| sudo tee \/var\/backups\/chronodesk\/deploy1-commit; else echo "PARE: [^"]+"; fi; unset K$/', $commands[$recordLine] ?? ''), 'roteiro unico: grava so o commit validado na estacao, do branch do deploy');
$branchLine = $first('[ "$B" = "codex/modernizacao-ui-backend" ]');
assert_same(true, $branchLine >= 0 && $branchLine < $recordLine, 'roteiro unico: confere o branch do servidor antes de gravar');
assert_same(true, $recordLine < $catFileLine && $catFileLine < $ancestorLine, 'roteiro unico: gravar, cat-file, conferencia de fast-forward');
assert_same(1, preg_match('/sudo git merge-base --is-ancestor HEAD "\$D1" && echo "fast-forward OK: .*" \|\| echo "PARE: [^"]+"/', $commands[$ancestorLine] ?? ''), 'roteiro unico: HEAD nao ancestral do commit gravado = PARE');
assert_same(true, $first('sudo git merge-base --is-ancestor 5d5c7c93db383c163b5e753f44375d7e5c34a117 HEAD') >= 0 && $first('sudo git merge-base --is-ancestor 5d5c7c93db383c163b5e753f44375d7e5c34a117 HEAD') < $recordLine, 'roteiro unico: pre-requisito dos deploys 1 a 7 no codigo, antes do dia');
// NN10 (aprovada em 2026-11-09, opcao 1): push do commit gravado para o branch
// codex no GitHub, pelo responsavel, no passo 9a; nunca --force.
$step9 = (int)strpos($runbook, "\n**9. ");
$pushLines = array_values(array_filter($psCommands, static fn (string $line): bool => preg_match('/\bgit push\b/', $line) === 1));
assert_same(true, strpos($prose, 'Nunca `merge` sem `--ff-only` no servidor') !== false && strpos($prose, 'decisao NN10 do responsavel, 2026-11-09') !== false && strpos($prose, 'o push e do responsavel (passo 9a)') !== false, 'roteiro unico: opcoes sem fast-forward e decisao NN10 registrada no passo 2');
assert_same(['git push origin <commit validado na estacao>:codex/modernizacao-ui-backend'], $pushLines, 'roteiro unico: um push so, do commit gravado para o branch codex (NN10)');
assert_same(true, $step9 > 0 && strpos($runbook, '9a. **Branch `codex/modernizacao-ui-backend` no GitHub (NN10') > $step9 && strpos($runbook, '```powershell' . "\n" . $pushLines[0]) > $step9, 'roteiro unico: o push fica no passo 9a, depois de um ciclo estavel');
assert_same(0, preg_match('/(^|\s)(--force|-f)(\s|$)/m', $text . "\n" . implode("\n", $psCommands)), 'roteiro unico: nenhum comando com --force');
assert_same(true, $mergeLine > $ancestorLine && $first('[ "$(sudo git rev-parse HEAD)" = "$(sudo cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit"') === $mergeLine + 1, 'roteiro unico: HEAD conferido logo depois do merge');

// ----------------------------------------------------------------------------
// 3. Rollback, APP_ENV, preflight e backup antes das migrations
// ----------------------------------------------------------------------------
$preDeployLine = $first('sudo git rev-parse HEAD | sudo tee /var/backups/chronodesk/pre-deploy-commit');
$appEnvLine = $first("sudo sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env");
$preflightLine = $first('bash -o pipefail -c \'sudo git show "$D1:scripts/preflight-deploy.sh" | sudo sh -s -- /var/www/.env\'');
$backupLine = $first('sudo systemctl start chronodesk-backup.service');
$firstMigrationArchive = $first('sudo git archive "$D1" migrations scripts/ensaio-migration.sh');
assert_same(true, $ancestorLine < $preDeployLine && $preDeployLine < $appEnvLine && $appEnvLine < $preflightLine && $preflightLine < $backupLine && $backupLine < $firstMigrationArchive, 'roteiro unico: ponto de rollback, APP_ENV, preflight do commit gravado e backup, antes das migrations');
assert_same(1, preg_match('/^K=\/var\/backups\/chronodesk\/env-antes-app-env-[^;]+; if sudo install -m 600 \/var\/www\/\.env "\$K"; then [^;]*; sudo sed -i .*; else echo "PARE: copia do \.env falhou, nada alterado"; fi; unset K$/', $commands[$appEnvLine] ?? ''), 'roteiro unico: copia do .env (600) antes de trocar o APP_ENV');
assert_same(true, strpos($commands[$preflightLine] ?? '', 'sudo git cat-file -e "$D1:scripts/preflight-deploy.sh" && ') !== false, 'roteiro unico: preflight com cat-file antes');

// ----------------------------------------------------------------------------
// 4. Migrations do deploy 1, em ordem, com ensaio e verificacao; nada do F8/F9
// ----------------------------------------------------------------------------
$applyLines = [];
foreach ($commands as $i => $line) {
    if (preg_match('/bash -o pipefail -c \'sudo git show "\$D1:migrations\/(2026\d{4}_\d{3}[a-z]?_[a-z0-9_]+\.sql)" \| docker exec -i -e MYSQL_PWD="\$P" Chrono_Desk_DB mysql -uroot sistema_pausas\'/', $line, $m) === 1) {
        $applyLines[$m[1]] = $i;
    }
}
$deploy1Migrations = ['20261005_011_schedule_history.sql', '20261013_014_employee_archive.sql', '20261013_019_presence_cutoff.sql', '20261007_012_absences.sql'];
assert_same($deploy1Migrations, array_keys($applyLines), 'roteiro unico: migrations aplicadas 011, 014, 019 e 012, nesta ordem');
assert_same(true, $applyLines['20261005_011_schedule_history.sql'] < $applyLines['20261013_014_employee_archive.sql'] && $applyLines['20261013_014_employee_archive.sql'] < $applyLines['20261013_019_presence_cutoff.sql'] && $applyLines['20261013_019_presence_cutoff.sql'] < $applyLines['20261007_012_absences.sql'], 'roteiro unico: ordem das linhas de aplicacao');
assert_same($mergeLine, $applyLines['20261007_012_absences.sql'], 'roteiro unico: a 012 e o merge no mesmo comando (decisao 3 do F3)');
foreach ($applyLines as $file => $i) {
    assert_same(true, strpos($commands[$i], "sudo git cat-file -e \"\$D1:migrations/{$file}\" && bash -o pipefail -c") !== false && strpos($commands[$i], "read -rsp 'Senha root do MySQL: ' P;") !== false, "roteiro unico: {$file} com cat-file e pipefail");
}
// Toda migration do repositorio posterior ao deploy 7 da fila (data a partir
// de 2026-10-05, o F1) esta no roteiro; migration nova (018, 020) reprova ate
// o roteiro decidir. O registro do corte nao e migration de esquema.
$repoMigrations = array_values(array_filter(array_map('basename', glob($root . '/migrations/2026*.sql') ?: []), static fn (string $name): bool => substr($name, 0, 8) >= '20261005' && strpos($name, '_registrar_corte') === false));
sort($repoMigrations);
$expectedSorted = $deploy1Migrations;
sort($expectedSorted);
assert_same($expectedSorted, $repoMigrations, 'repositorio: as migrations da Parte B sao as do deploy 1 (nova migration exige o roteiro)');
assert_same(0, preg_match('/_(013|018|020)_/', str_replace("grep -cE '_(013|018|020)_'", '', $text)), 'roteiro unico: nenhum comando com a 013, a 018 ou a 020');
assert_same(true, $first("grep -cE '_(013|018|020)_'") > $catFileLine && $first("grep -cE '_(013|018|020)_'") < $firstMigrationArchive, 'roteiro unico: conferencia de que o commit nao traz 013, 018 nem 020');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; export D1=\$\(sudo cat \/var\/backups\/chronodesk\/deploy1-commit\); E=\$\(sudo mktemp -d \/root\/ensaio-deploy1\.XXXXXX\); export E; bash -o pipefail -c \'sudo git archive "\$D1" migrations scripts\/ensaio-migration\.sh \| sudo tar -x -C "\$E"\' && echo "extraido em \$E" \|\| echo "PARE: [^"]+"$/', $commands[$firstMigrationArchive] ?? ''), 'roteiro unico: migrations e script de ensaio extraidos do commit gravado, com pipefail');
assert_same(0, preg_match('/(^|[\s;])bash scripts\/ensaio-migration\.sh/m', $text), 'roteiro unico: ensaio so com o script do commit gravado (o do servidor e anterior ao F1)');
$ensaios = ['20261005_011_schedule_history.sql' => ['d1_011 '], '20261013_014_employee_archive.sql' => ['d1_014 ', 'd1_014_dados ', 'd1_014_rollback '], '20261013_019_presence_cutoff.sql' => ['d1_019 ', 'd1_019_registro ', 'd1_019_passado '], '20261007_012_absences.sql' => ['d1_012 ', 'd1_012_parada ', 'd1_012_rollback ']];
foreach ($ensaios as $file => $variants) {
    foreach ($variants as $variant) {
        $line = $first('bash "$E/scripts/ensaio-migration.sh" ' . $variant);
        assert_same(true, $line > $firstMigrationArchive && $line < $applyLines[$file], "roteiro unico: ensaio {$variant}antes da aplicacao de {$file}");
    }
}
$checkFiles = ['20261005_011_schedule_history.sql' => '20261005_011_schedule_history_check.sql', '20261013_014_employee_archive.sql' => '20261013_014_employee_archive_check.sql', '20261013_019_presence_cutoff.sql' => '20261013_019_presence_cutoff_check.sql'];
foreach ($checkFiles as $file => $check) {
    assert_same(true, strpos($commands[$applyLines[$file] + 1] ?? '', "< <(sudo cat \"\$E/migrations/verificacao/{$check}\"); unset P") !== false, "roteiro unico: verificacao logo depois de {$file}");
}
assert_same(true, strpos($commands[$mergeLine + 3] ?? '', '< migrations/verificacao/20261007_012_absences_check.sql; unset P') !== false, 'roteiro unico: verificacao da 012 depois do merge');
$q4Line = $first('SELECT COUNT(*) AS ausencias FROM portal_absences;');
$qf4Line = $first('AS excecoes_de_inativos;');
$check019Before = $first('< <(sudo cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P');
assert_same(true, $q4Line > $applyLines['20261013_019_presence_cutoff.sql'] && $q4Line < $mergeLine && $qf4Line < $applyLines['20261013_014_employee_archive.sql'] && $qf4Line > $applyLines['20261005_011_schedule_history.sql'] && $check019Before < $first('bash "$E/scripts/ensaio-migration.sh" d1_019 '), 'roteiro unico: Q4 antes da 012, Q-F4 antes da 014, verificacao da 019 antes do ensaio');
$verifyLoop = $first('for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do');
assert_same(true, $verifyLoop > $mergeLine, 'roteiro unico: as quatro verificacoes de novo no pos-deploy');

// ----------------------------------------------------------------------------
// 5. Ensaio do codigo: /tmp/.env, pipefail, www-data, todos os QAs PHP
// ----------------------------------------------------------------------------
$tmpEnvLine = $first('[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE:');
$qaArchiveLine = $first('Q=$(sudo mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX)');
$lintLine = $first('sudo find "$Q" -name \'*.php\'');
$qaLoopLine = $first('for q in ');
$qaCleanupLine = $first('case "$Q" in /tmp/ensaio-deploy1-qa.*) sudo rm -r "$Q"');
assert_same(true, $applyLines['20261013_019_presence_cutoff.sql'] < $tmpEnvLine && $tmpEnvLine < $qaArchiveLine && $qaArchiveLine < $lintLine && $lintLine < $qaLoopLine && $qaLoopLine < $qaCleanupLine && $qaCleanupLine < $mergeLine, 'roteiro unico: ensaio do codigo depois das migrations 011, 014 e 019 e antes do merge');
assert_same(1, preg_match('/bash -o pipefail -c \'sudo git archive "\$D1" \| sudo tar -x -C "\$Q"\' && sudo chown -R www-data: "\$Q" && echo "extraido em \$Q"/', $commands[$qaArchiveLine] ?? ''), 'roteiro unico: commit inteiro extraido com pipefail e passado so ao www-data');
assert_same([$qaArchiveLine], array_keys(array_filter($commands, static fn (string $line): bool => preg_match('/\b(chown|chmod)\b/', $line) === 1)), 'roteiro unico: permissao so do diretorio do ensaio de codigo');
assert_same(true, strpos($commands[$qaLoopLine] ?? '', '[ ! -e /tmp/.env ] && for q in ') === 0 && strpos($commands[$qaLoopLine], 'sudo -u www-data php "$Q/scripts/$q.php"') !== false, 'roteiro unico: QAs so sem /tmp/.env, como www-data, com php arquivo.php');
preg_match('/for q in ([a-z0-9 -]+); do/', $commands[$qaLoopLine] ?? '', $qaList);
$runbookQas = explode(' ', trim($qaList[1] ?? ''));
$repoQas = array_map(static fn (string $path): string => basename($path, '.php'), glob($root . '/scripts/qa-*.php') ?: []);
$sortedRunbookQas = $runbookQas;
sort($sortedRunbookQas);
sort($repoQas);
assert_same($repoQas, $sortedRunbookQas, 'roteiro unico: o ensaio roda todos os QAs PHP do repositorio (QA novo exige o roteiro)');
assert_same(1, preg_match('/^case "\$E" in \/root\/ensaio-deploy1\.\*\) sudo rm -r "\$E" && echo "[^"]+";; \*\) echo "PARE: [^"]+";; esac; unset E D1$/', $commands[$first('case "$E" in /root/ensaio-deploy1.*)')] ?? ''), 'roteiro unico: limpeza do ensaio das migrations so do proprio diretorio');
assert_same(2, count(array_filter($commands, static fn (string $line): bool => preg_match('/\brm\b/', $line) === 1)), 'roteiro unico: so as duas remocoes guardadas por case (os dois ensaios)');

// ----------------------------------------------------------------------------
// 6. Q-F6, Q-F7, base do criterio, build e recarga
// ----------------------------------------------------------------------------
$qf6Line = $first('AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa');
$qf7Line = $first('fora_mapa_escala = 1) AS fora_do_mapa');
$labelLines = $all('AS rotulo_que_o_php_nao_reconhece');
$baseLines = $all('bash <(sudo git show "$D1:scripts/medir-polling.sh")');
$referenceLines = $all('bash <(sudo git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)');
assert_same(true, $qf6Line > $qaCleanupLine && $qf6Line < $mergeLine && $qf7Line > $qaCleanupLine && $qf7Line < $mergeLine && count(array_filter($labelLines, static fn (int $i): bool => $i > $qaCleanupLine && $i < $mergeLine)) === 1, 'roteiro unico: Q-F6 e Q-F7 completas e rotulo imediatamente antes do merge');
assert_same([2, 2], [count(array_filter($referenceLines, static fn (int $i): bool => $i < $recordLine)), count(array_filter($baseLines, static fn (int $i): bool => $i > $qaCleanupLine && $i < $mergeLine))], 'roteiro unico: referencia antes do dia (branch remoto) e base do criterio do commit gravado, antes do merge');
foreach (array_merge($referenceLines, $baseLines) as $i) {
    assert_same(1, preg_match('/git cat-file -e (origin\/feat\/employee-form-rhf-zod:|"\$D1:)scripts\/medir-polling\.sh"? && (URL=http:\/\/127\.0\.0\.1\/api\/portal\/dashboard\.php )?bash <\(sudo git show/', $commands[$i]), 'roteiro unico: medicao com cat-file antes: ' . substr($commands[$i], 0, 60));
}
$buildLine = $first('cd /var/www/chronodesk/frontend && sudo npm ci && sudo npm run build');
$reloadLine = $first('sudo systemctl reload "php$(php -r');
assert_same(true, $buildLine > $mergeLine && $reloadLine === $buildLine + 1, 'roteiro unico: build e recarga do PHP-FPM depois do merge');

// ----------------------------------------------------------------------------
// 7. Verificacao: legado, D5, 16c antes do registro do corte
// ----------------------------------------------------------------------------
$legacyLines = $all('for p in / /index.php /login.php /admin_login.php /admin_logout.php /admin.php /metricas.php /logout.php; do');
assert_same(true, count($legacyLines) === 2 && $legacyLines[0] < $recordLine && $legacyLines[1] > $reloadLine, 'roteiro unico: curl das paginas legadas antes do dia e depois da recarga');
assert_same(true, strpos($prose, 'Esperado: `GET 302` e `POST 410` em todas as paginas, menos o `/logout.php` (`GET 410` e `POST 410`, HH5); `403` nos dois JS.') !== false, 'roteiro unico: esperado das paginas legadas (302, 410, 403)');
$d5Line = $first('SELECT DISTINCT a.employee_id AS id_cadastro');
$cutoffLine = $first('C=$(docker exec');
$query16cLine = $first('AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0');
$registerLine = $first('cat migrations/20261013_019_registrar_corte.sql; } | docker exec');
assert_same(true, $reloadLine < $d5Line && $d5Line < $cutoffLine && $cutoffLine < $query16cLine && $query16cLine < $registerLine, 'roteiro unico: D5, data do corte, 16c e so entao o registro, depois da recarga');
$approved16c = "SET @corte := '\$C'; WITH RECURSIVE dias (d) AS (SELECT CAST(@corte AS DATE) UNION ALL SELECT d + INTERVAL 1 DAY FROM dias WHERE d < @corte + INTERVAL 1 MONTH - INTERVAL 1 DAY) SELECT COUNT(*) AS ativos_n1_n2, COALESCE(SUM(NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= @corte AND (r.effective_until IS NULL OR r.effective_until >= @corte))), 0) AS sem_regra_no_corte, COALESCE(SUM(EXISTS (SELECT 1 FROM dias WHERE NOT EXISTS (SELECT 1 FROM portal_schedule_rules r WHERE r.employee_id = f.id AND r.rule_type <> 'undefined' AND r.effective_from <= dias.d AND (r.effective_until IS NULL OR r.effective_until >= dias.d)))), 0) AS sem_regra_na_competencia FROM funcionarios f WHERE f.ativo = 1 AND f.fora_mapa_escala = 0 AND LOWER(f.equipe) IN ('n1', 'n2');";
assert_same(true, strpos($commands[$query16cLine] ?? '', $approved16c) !== false, 'roteiro unico: 16c com o texto aprovado no F5');
assert_same(true, strpos($commands[$cutoffLine] ?? '', "DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-16'), DATE_FORMAT(CURDATE(), '%Y-%m-16')") !== false && strpos($commands[$cutoffLine], "[[ \"\$C\" =~ ^[0-9]{4}-[0-9]{2}-16\$ ]] && echo \"corte planejado: \$C\" || echo \"PARE") !== false, 'roteiro unico: data do corte pela D2, conferida no formato');
assert_same(true, strpos($commands[$registerLine] ?? '', "printf \"SET @corte := '%s'; SET @autor := '%s';\\n\" \"\$C\" '<login do operador>'") !== false && strpos($commands[$registerLine + 1] ?? '', '20261013_019_presence_cutoff_check.sql') !== false, 'roteiro unico: registro com o C da 16c e verificacao da 019 logo depois');
assert_same([true, true, true], [strpos($prose, 'Verificacao da 019 com **`inconsistente = 1`** (pendencia 16a): pare') !== false, strpos($prose, 'Com qualquer uma maior que `0`, o registro do corte **nao roda**') !== false, strpos($prose, '**`vinculos_de_inativos = 0` e `vinculos_fora_do_mapa = 0`**; diferente, **pare e avise**') !== false], 'roteiro unico: regras de parada da 16a, da 16c e da Q-F6 depois da D5');

// ----------------------------------------------------------------------------
// 8. Desligamento, copias do .env e rollback em degraus
// ----------------------------------------------------------------------------
$switchLine = $first("sudo sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env");
assert_same(1, preg_match('/^K=\/var\/backups\/chronodesk\/env-antes-interruptor-[^;]+; if sudo install -m 600 \/var\/www\/\.env "\$K"; then [^;]*; if sudo grep -q .*sudo sed -i .*; fi; else echo "PARE: copia do \.env falhou, nada alterado"; fi; unset K$/', $commands[$switchLine] ?? ''), 'roteiro unico: desligamento com copia do .env (600) antes do sed');
assert_same(true, $switchLine > $registerLine && $first("sudo grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/") === $switchLine - 1 && strpos($commands[$switchLine + 1] ?? '', 'preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED') !== false && strpos($commands[$switchLine + 2] ?? '', 'sudo systemctl reload "php$(php -r') === 0, 'roteiro unico: pool do PHP-FPM, copia, preflight e recarga');
$listCopies = $first("sudo find /var/backups/chronodesk -maxdepth 1 -type f \\( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \\) -printf");
$deleteCopies = $first("sudo find /var/backups/chronodesk -maxdepth 1 -type f \\( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \\) -print -delete");
assert_same(true, $listCopies > $switchLine && $deleteCopies === $listCopies + 1 && count($all('-delete')) === 1, 'roteiro unico: limpeza das copias env-antes-* (listar, depois apagar), so elas');
assert_same(true, strpos($prose, 'Depois de conferidas e **com aprovacao do responsavel**') !== false, 'roteiro unico: limpeza das copias so com aprovacao');
$rollbackOrder = [];
foreach ($commands as $i => $line) {
    if (preg_match('/sudo git show "\$D1:migrations\/rollback\/(2026\d{4}_(\d{3})_[a-z_]+_down\.sql)" .* && echo "rollback da (\d{3}) aplicado"/', $line, $m) === 1 && $m[2] === $m[3]) {
        $rollbackOrder[] = $m[2];
    }
}
$checkoutLine = $first('sudo git checkout --detach "$(sudo cat /var/backups/chronodesk/pre-deploy-commit)"');
$cycleDownLine = $first('sudo git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql"');
assert_same(['019', '014', '012', '011'], $rollbackOrder, 'roteiro unico: rollback das migrations na ordem inversa, lido do commit gravado');
assert_same(true, $cycleDownLine > $switchLine && $cycleDownLine < $checkoutLine && $checkoutLine < $first('rollback da 019 aplicado'), 'roteiro unico: ciclo do F2B antes do codigo, codigo antes das migrations');
foreach (['**Risco LGPD de voltar o codigo**', '**F3:**', '**SEC-07b:**', '**DF7 (F6):**', '**DV9 a DV11 (F7):**', '**Degrau 2: codigo (so com aprovacao previa do responsavel).**', '**Degrau 3: migrations (so com aprovacao, so com as tabelas vazias ou com a copia de seguranca que o proprio rollback faz)**'] as $needle) {
    assert_same(true, strpos($prose, $needle) !== false, "roteiro unico: rollback em degraus com {$needle}");
}

// ----------------------------------------------------------------------------
// 9. So contagens; senha sem eco e apagada
// ----------------------------------------------------------------------------
assert_same(0, preg_match('/\b(f\.nome|nome AS|SELECT nome|ad_login|registered_by|employee_name|employee_login)\b/', $text), 'roteiro unico: nenhuma consulta devolve nome, login ou autor');
assert_same(0, preg_match('/\s-p(?!rint|ath)\S/', $text), 'roteiro unico: nenhuma senha em -p na linha de comando');
$passwordLines = $all('MYSQL_PWD="$P"');
assert_same(true, count($passwordLines) >= 30, 'roteiro unico: consultas ao MySQL presentes');
foreach ($passwordLines as $i) {
    $line = $commands[$i];
    assert_same(true, strpos($line, "read -rsp 'Senha root do MySQL: ' P;") !== false && preg_match('/unset P( U)?(;|$| done$)/', rtrim($line)) === 1 && preg_match('/\s-p\S/', $line) === 0, 'roteiro unico: senha lida sem eco e apagada na mesma linha: ' . substr($line, 0, 60));
}
$secretLines = $all('SECRET_KEY');
$secretLine = 'sudo awk -F= \'/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}\' /var/www/.env';
assert_same([1, $secretLine], [count($secretLines), $commands[$secretLines[0] ?? -1] ?? ''], 'roteiro unico: a SECRET_KEY so aparece na conferencia que mostra o tamanho e se e texto de exemplo, nunca o valor');

// ----------------------------------------------------------------------------
// 10. Pre-requisitos, -Browser na estacao, aviso a gestao
// ----------------------------------------------------------------------------
foreach (['0a. ', '0b. ', '0c. ', '0d. ', '0e. ', '0f. ', '0g. ', '0h. ', '0i. ', '0j. ', '0k. ', '0l. '] as $item) {
    assert_same(true, strpos($runbook, "\n{$item}") !== false, "roteiro unico: pre-requisito {$item}presente");
}
foreach (['Pendencia 16a', 'Pendencia 16b', 'Pendencia 16c', '0a2: `APP_ENV`', '`gitleaks` instalado', 'Logs e `curl` das paginas legadas', 'OBRIGATORIO, na estacao, sobre o commit que vai ser gravado', 'Q-F6, variante', 'Q-F7, variante', 'Referencia do `status.php` e do `dashboard.php`'] as $needle) {
    assert_same(true, strpos($prose, $needle) !== false, "roteiro unico: pre-requisito {$needle}");
}
$browserLine = array_values(array_filter($psCommands, static fn (string $line): bool => strpos($line, 'test-local.ps1 -SkipHttp -Browser') !== false));
assert_same(1, count($browserLine), 'roteiro unico: -Browser na estacao, numa linha');
assert_same(true, strpos($browserLine[0] ?? '', 'git worktree add --detach ..\\chronodesk-deploy1 origin/feat/employee-form-rhf-zod') !== false && substr(rtrim($browserLine[0] ?? ''), -18) === 'git rev-parse HEAD', 'roteiro unico: -Browser sobre o commit do branch do deploy, com o hash no fim');
assert_same(true, strpos($runbook, '0k. **Roteiro de navegador') < strpos($runbook, '**2. Branch'), 'roteiro unico: -Browser antes de gravar o commit');
preg_match_all('/^> .*$/m', $runbook, $noticeLines);
$notice = implode("\n", $noticeLines[0]);
assert_same([true, true, true], [strpos($notice, 'ate [N_PAS] PAs') !== false, strpos($notice, '[N_SEM_REGRA] tecnicos') !== false, strpos($notice, '[DATA_CORTE]') !== false], 'aviso a gestao: marcadores [N_PAS], [N_SEM_REGRA] e [DATA_CORTE]');
assert_same(0, preg_match('/\d/', str_replace(['N1', 'N2'], '', $notice)), 'aviso a gestao: sem numero fixo (so os marcadores)');
assert_same(0, preg_match('/contrato|cr[ií]tico|F8|F9|almo[cç]o por/i', $notice), 'aviso a gestao: nada do F8 nem do F9');
foreach (['escala do dia', 'contorno vermelho', 'E um aviso, nao um bloqueio', '"CIs disponiveis"', 'A Lideranca aparece numa linha a parte', 'tela Ausencias', '"Arquivar"', 'telas antigas'] as $needle) {
    assert_same(true, strpos((string)preg_replace('/\s+/', ' ', str_replace("\n> ", ' ', $notice)), $needle) !== false, "aviso a gestao: fala de {$needle}");
}
assert_same([true, true, true], [strpos($prose, '`[N_PAS]` e o `pas_compartilhados_hoje` da Q-F6') !== false, strpos($prose, '`[N_SEM_REGRA]` e o `n1_n2_sem_regra_hoje` da Q-F7') !== false, strpos($prose, '`[DATA_CORTE]` e o `corte planejado` do passo 0g') !== false], 'aviso a gestao: a consulta de cada marcador');

// ----------------------------------------------------------------------------
// 11. Commit 1 do F8 vai junto sem efeito: EmployeeLunchPolicy so no QA dele
// ----------------------------------------------------------------------------
$callers = [];
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), static function (SplFileInfo $file): bool {
    return !in_array($file->getFilename(), ['.git', 'node_modules', 'dist', '_referencias'], true) && !($file->isDir() && $file->getFilename() === 'app');
}));
foreach ($iterator as $file) {
    if (!preg_match('/\.(php|js|jsx|ts|tsx|mjs|sh|ps1|htaccess)$/', $file->getFilename())) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array($relative, ['services/EmployeeLunchPolicy.php', 'scripts/qa-employee-lunch.php', 'scripts/qa-deploy-runbook.php'], true)) {
        continue;
    }
    if (strpos((string)file_get_contents($file->getPathname()), 'EmployeeLunchPolicy') !== false) {
        $callers[] = $relative;
    }
}
assert_same([], $callers, 'commit 1 do F8: EmployeeLunchPolicy so no QA dele (vai no deploy 1 sem efeito)');
assert_same(true, strpos($prose, 'so e chamado pelo `scripts/qa-employee-lunch.php`') !== false, 'roteiro unico: registra que o commit 1 do F8 vai sem efeito');

// ----------------------------------------------------------------------------
// 12. .env.production.example sem HTTPS forcado (o portal nao tem HTTPS proprio)
// ----------------------------------------------------------------------------
// FORCE_HTTPS=true derruba o portal (301 para um https:// que nao atende; o
// preflight reprova) e SESSION_COOKIE_SECURE=true faz o navegador descartar o
// cookie de sessao recebido por HTTP. O exemplo tem de vir desligado.
$readOptional = static fn (string $path): string => is_file($path) ? str_replace("\r\n", "\n", (string)file_get_contents($path)) : '';
$prodExample = $readOptional($root . '/.env.production.example');
preg_match_all('/^(FORCE_HTTPS|SESSION_COOKIE_SECURE)=(.*)$/m', $prodExample, $httpsSettings, PREG_SET_ORDER);
$httpsValues = [];
foreach ($httpsSettings as $setting) {
    $httpsValues[] = $setting[1] . '=' . trim($setting[2]);
}
sort($httpsValues);
assert_same(['FORCE_HTTPS=false', 'SESSION_COOKIE_SECURE=false'], $httpsValues, '.env.production.example: FORCE_HTTPS e SESSION_COOKIE_SECURE desligados, uma vez cada');

// ----------------------------------------------------------------------------
// 13. Folha de execucao do dia: os mesmos comandos do roteiro, na mesma ordem
// ----------------------------------------------------------------------------
$sheet = $readOptional($root . '/docs/DEPLOY1_FOLHA_DO_DIA.md');
$fencedCommands = static function (string $doc): array {
    preg_match_all('/```(bash|powershell)\n(.*?)```/s', $doc, $blocks, PREG_SET_ORDER);
    $lines = [];
    foreach ($blocks as $block) {
        foreach (explode("\n", $block[2]) as $line) {
            if (trim($line) !== '') {
                $lines[] = $block[1] . ': ' . $line;
            }
        }
    }
    return [$lines, count($blocks)];
};
[$runbookAll] = $fencedCommands($runbook);
[$sheetAll, $sheetBlocks] = $fencedCommands($sheet);
assert_same(true, $sheet !== '' && count($runbookAll) === count($commands) + count($psCommands), 'folha do dia: presente; comandos do roteiro lidos');
assert_same($runbookAll, $sheetAll, 'folha do dia: os mesmos comandos do roteiro, na mesma ordem, com a mesma linguagem');
assert_same(count($sheetAll), $sheetBlocks, 'folha do dia: um comando por bloco');
$sheetLines = explode("\n", $sheet);
$unmarked = [];
foreach ($sheetLines as $i => $line) {
    if (preg_match('/^```(bash|powershell)$/', $line) !== 1) {
        continue;
    }
    $previous = '';
    for ($j = $i - 1; $j >= 0 && $previous === ''; $j--) {
        $previous = trim($sheetLines[$j]);
    }
    if (preg_match('/^- \[ \] \*\*\d+\.\*\* .*Esperado: .+ \*\*PARE se:\*\* .+/', $previous) !== 1) {
        $unmarked[] = $i + 1;
    }
}
assert_same([], $unmarked, 'folha do dia: cada comando com caixa [ ], numero, Esperado e PARE se logo acima');
preg_match_all('/^- \[ \] \*\*(\d+)\.\*\* /m', $sheet, $sheetNumbers);
assert_same(range(1, count($sheetAll)), array_map('intval', $sheetNumbers[1]), 'folha do dia: comandos numerados de 1 em diante, na ordem');
$sheetWithoutLoopback = str_replace('127.0.0.1', '', $sheet);
assert_same([0, 0, 0, 0], [
    preg_match('/\b\d{1,3}(\.\d{1,3}){3}\b/', $sheetWithoutLoopback),
    preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\./', $sheet),
    preg_match('/\b[a-z0-9-]+(\.[a-z0-9-]+)*\.(local|lan|corp|intra|internal|com|br|net|org)\b/i', $sheet),
    preg_match('/MYSQL_PWD=(?!"\$P")|\s-p(?!rint|ath)\S/', $sheet),
], 'folha do dia: sem IP (alem do 127.0.0.1), e-mail, hostname ou senha');

echo "QA deploy runbook OK ({$checks} verificacoes)\n";
