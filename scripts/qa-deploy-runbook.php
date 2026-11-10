<?php
declare(strict_types=1);

// ============================================================================
// Travas do roteiro unico do deploy 1 da Parte B (DEPLOY_LINUX.md, desenho
// 9.13; decisao de 2026-11-06). Le so o documento e a arvore do repositorio:
// sem banco, sem rede, sem executar comando do roteiro.
//
// Desde 2026-11-09 (9.13.4) o roteiro e o consolidado de tudo desde o
// 596db67, o codigo que esta no ar.
//
// O que fica travado: um comando por linha; sem pull; um fetch so, antes de
// gravar o commit; ordem gravar -> cat-file -> conferencia de fast-forward ->
// regras locais do .htaccess no commit -> archive com pipefail -> ensaio ->
// merge --ff-only do commit gravado; so contagens; senha sem eco e com
// unset; o 0a com o 596db67 e a 015, a 019 e as manutencao_* ausentes; as
// migrations desde o 596db67 (015, 016, 017, 011, 014, 019, a 012 junto com
// o merge e a 016b depois), cada uma com o "antes" e a verificacao no dia, e
// nenhuma do F8 nem do F9; o ensaio do BIZ-02 e os 14 ensaios das migrations
// na vespera (0n e 0q; RR7 revista, 9.13.6), na copia sistema_pausas_ensaio
// (ORIGEM), conferida antes e depois, nunca criada pelo roteiro e apagada uma
// vez so, no fim do 0q, com o nome digitado; nenhum ensaio no dia, o 0b de
// novo no 4a e o 7m so conferindo que a copia nao existe; a data exigida,
// com o corte pela D2, a vespera no dia anterior e a indisponibilidade dentro
// da janela e no aviso; o espaco livre com minimo no 0m e no 3d (antes do backup e do
// npm ci); o backup pre-dia na limpeza do 9e; a manutencao
// antes do merge, o corte do BIZ-02 logo depois e so entao a 016b, a
// recarga e a reabertura; a copia do .htaccess local antes, o descarte no
// comando do merge e a volta dela no rollback; o ensaio de codigo com todos os QAs PHP e a
// conferencia do /tmp/.env; o aviso a gestao com marcadores; o commit 1 do F8
// sem chamada fora do QA dele; o push do branch codex so no passo 9a, sem
// --force (NN10); o .env.production.example sem HTTPS forcado e com o
// APP_BASE_URL em http://; o test-linux.sh e o test-local.ps1 com todos os QAs
// PHP; a folha do dia (docs/DEPLOY1_FOLHA_DO_DIA.md) com os mesmos comandos, na
// mesma ordem, e as mesmas linhas de data e janela, vespera, indisponibilidade
// e quem acompanha.
//
// Desde 2026-11-10 (9.13.7, auditoria pre-deploy em
// docs/AUDITORIA_PRE_DEPLOY1.md): o 0r na vespera (preflight do commit
// validado contra o .env real, npm ping, contagens M9/C3/A1, cron e timers e
// o log de erros do PHP); o diretorio de backup criado antes do passo 2
// (M1); a copia do app/ no 3a e a volta dela no degrau 2, sem npm (C2); o
// preflight (3b) antes da troca do APP_ENV (3c), que vale na hora (C1); a
// espera de 10 s e o segundo dump logo depois do merge, antes do corte do
// BIZ-02 (A2, risco 5); o teste de fumaca (scripts/smoke-deploy1.sh) no 6f,
// antes da reabertura (C3); o DROP da fotografia do BIZ-02 no degrau 2 so
// sem a correcao historica (A3); o aviso sem prometer o fim da observacao
// da excecao de escala visivel a todos (A1).
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
// Shell root sem sudo (9.13.7, achado de 2026-11-10): o /etc/sudoers da VM
// tem Defaults noexec; com sudo, awk, node, gzip e o PHP filho nao rodam.
// Nenhum comando do roteiro (nem da folha, secao 13) leva sudo; o que roda
// como www-data usa runuser. A conferencia do ambiente abre a vespera (00) e
// o dia (passo 1), o mesmo comando, antes de qualquer outro do dia.
$sudoLines = array_values(array_filter(array_merge($commands, $psCommands), static fn (string $line): bool => preg_match('/\bsudo\b/', $line) === 1));
assert_same([], $sudoLines, 'roteiro unico: nenhum comando com sudo (Defaults noexec no servidor; shell root do sudo -i)');
$envCheckLines = array_keys(array_filter($commands, static fn (string $line): bool => strpos($line, 'R=OK; [ "$(id -u)" = 0 ] && echo "OK id -u = 0"') === 0));
$envCheck = $commands[$envCheckLines[0] ?? -1] ?? '';
assert_same([2, 0, true], [count($envCheckLines), $envCheckLines[0] ?? -1, count($envCheckLines) === 2 && $envCheck === $commands[$envCheckLines[1]]], 'roteiro unico: conferencia do ambiente como 1o comando da vespera e de novo no dia, o mesmo comando');
foreach (['echo "OK bash -c"', 'tar -czf - -C /etc hostname 2>/dev/null | tar -tzf -', '-exec echo ok \;', 'runuser -u www-data -- php -v', 'runuser -u www-data -- php -r \'$p = proc_open([PHP_BINARY', 'npm -v >/dev/null 2>&1 && echo "OK npm -v"', 'git -C /var/www/chronodesk rev-parse --git-dir', '[ "$R" = OK ] && echo "ambiente: OK" || echo "PARE: ambiente com BLOQUEADO"; unset R'] as $needle) {
    assert_same(true, strpos($envCheck, $needle) !== false, "roteiro unico: conferencia do ambiente com {$needle}");
}
assert_same(true, strpos($prose, 'abre um shell root com `sudo -i` no inicio e roda todos os comandos nele, sem o prefixo `sudo`') !== false && strpos($prose, '**Qualquer `BLOQUEADO` e PARE:**') !== false, 'roteiro unico: regra do shell root e PARE no BLOQUEADO');
// Data e janela: preenchidas, com o corte do F5 pela D2 (a mesma regra do 0i
// e do 7k: dia 16 ou depois vai para o mes seguinte). Desde 2026-11-09
// (9.13.6) a data e exigida: 11/11/2026, das 19:00 as 23:00. Quem acompanha:
// so o papel, sem nome. A folha do dia repete as linhas iguais (secao 13).
$d = [];
preg_match_all('/^- \*\*Data e janela do deploy\*\*, fora do horario de operacao: (.+)$/m', $runbook, $dateLines);
preg_match_all('/^- \*\*Quem acompanha\*\* \(alem de quem executa\): (.+)$/m', $runbook, $companionLines);
$dateLine = count($dateLines[0]) === 1 ? $dateLines[0][0] : '';
$companionLine = count($companionLines[0]) === 1 ? $companionLines[0][0] : '';
$companion = $companionLines[1][0] ?? '';
$deployDate = null;
$plannedCutoff = '';
$statedCutoff = '';
$windowOk = false;
if ($dateLine !== '' && preg_match('#^(\d{2})/(\d{2})/(\d{4}), das ([01]\d|2[0-3]):([0-5]\d) as ([01]\d|2[0-3]):([0-5]\d) \(horario de Brasilia\); corte do F5 pela D2: (\d{2})/(\d{2})/(\d{4})\.$#', $dateLines[1][0], $d) === 1 && checkdate((int)$d[2], (int)$d[1], (int)$d[3]) && checkdate((int)$d[9], (int)$d[8], (int)$d[10])) {
    $deployDate = new DateTimeImmutable(sprintf('%s-%s-%s', $d[3], $d[2], $d[1]));
    $windowOk = (int)$d[4] * 60 + (int)$d[5] < (int)$d[6] * 60 + (int)$d[7];
    $d2 = (int)$deployDate->format('d') >= 16 ? $deployDate->modify('first day of next month') : $deployDate;
    $plannedCutoff = $d2->format('Y-m-16');
    $statedCutoff = sprintf('%s-%s-%s', $d[10], $d[9], $d[8]);
}
// Desde 2026-11-09 (9.13.6) a data e exigida: A DEFINIR reprova.
assert_same(true, $deployDate !== null && $windowOk, 'roteiro unico: data do deploy (DD/MM/AAAA) e janela (inicio antes do fim) preenchidas, numa linha so (A DEFINIR nao vale mais)');
assert_same(true, $plannedCutoff !== '' && $plannedCutoff === $statedCutoff, 'roteiro unico: corte do F5 coerente com a data pela D2');
assert_same(0, preg_match('/A DEFINIR/', $dateLine), 'roteiro unico: data sem A DEFINIR');
$windowText = $deployDate !== null ? sprintf('%s, das %s:%s as %s:%s', $deployDate->format('d/m/Y'), $d[4], $d[5], $d[6], $d[7]) : '';
assert_same(true, $windowText !== '' && strpos($prose, '- a data e a janela (**' . $windowText . '**) e quem acompanha presente;') !== false, 'roteiro unico: o passo 1 confere a data e a janela da linha da data');
// Vespera: o dia anterior ao deploy (pre-requisitos, BIZ-02 e os 14 ensaios).
preg_match_all('/^- \*\*Vespera\*\* \(pre-requisitos do passo 0, ensaio do BIZ-02 e ensaios das migrations\): (\d{2})\/(\d{2})\/(\d{4})\.$/m', $runbook, $eveLines);
$eveLine = count($eveLines[0]) === 1 ? $eveLines[0][0] : '';
$eveDate = $eveLine !== '' && checkdate((int)$eveLines[2][0], (int)$eveLines[1][0], (int)$eveLines[3][0]) ? sprintf('%s-%s-%s', $eveLines[3][0], $eveLines[2][0], $eveLines[1][0]) : '';
assert_same(true, $deployDate !== null && $eveDate === $deployDate->modify('-1 day')->format('Y-m-d'), 'roteiro unico: vespera preenchida, o dia anterior ao deploy');
// Indisponibilidade prevista: dentro da janela; o aviso traz a mesma.
preg_match_all('/^- \*\*Indisponibilidade prevista\*\* \(manutencao, passos 6b a 6g; inventario, secao 9\): cerca de (\d+) minutos, entre ([01]\d|2[0-3]):([0-5]\d) e ([01]\d|2[0-3]):([0-5]\d), com o turno da noite em operacao\.$/m', $runbook, $outageLines);
$outageLine = count($outageLines[0]) === 1 ? $outageLines[0][0] : '';
$outageStart = $outageLine !== '' ? (int)$outageLines[2][0] * 60 + (int)$outageLines[3][0] : -1;
$outageEnd = $outageLine !== '' ? (int)$outageLines[4][0] * 60 + (int)$outageLines[5][0] : -1;
assert_same(true, $deployDate !== null && $outageLine !== '' && $outageStart >= (int)$d[4] * 60 + (int)$d[5] && $outageStart < $outageEnd && $outageEnd <= (int)$d[6] * 60 + (int)$d[7] && (int)$outageLines[1][0] <= $outageEnd - $outageStart, 'roteiro unico: indisponibilidade prevista dentro da janela e cabendo no intervalo');
assert_same([true, 0], [$companionLine !== '' && substr($companion, -1) === '.', preg_match('/A DEFINIR|[\[\]<>]/', $companion)], 'roteiro unico: quem acompanha preenchido (uma linha so, sem marcador)');
$stepOrder = array_map(static fn (string $step): int => (int)strpos($runbook, $step), ["\n00. ", "\n0a. ", "\n0n. ", "\n0p. ", "\n0q. ", "\n0r. ", "\n**1. ","\n**2. ", "\n**3. ", "\n**4. ", "\n**5. ", "\n**6. ", "\n**7. ", "\n**8. ", "\n**9. "]);
$sortedSteps = $stepOrder;
sort($sortedSteps);
assert_same([true, $sortedSteps], [!in_array(0, $stepOrder, true), $stepOrder], 'roteiro unico: passos 0 a 9 presentes e na ordem pedida');

// ----------------------------------------------------------------------------
// 2. Branch, um fetch so, commit gravado, cat-file, fast-forward, sem pull
// ----------------------------------------------------------------------------
$recordLine = $first('| tee /var/backups/chronodesk/deploy1-commit');
$catFileLine = $first('git cat-file -e "$D1^{commit}"');
$ancestorLine = $first('git merge-base --is-ancestor HEAD "$D1"');
$mergeLines = $all('git merge');
$mergeLine = $first('&& git merge --ff-only "$D1"; unset P');
$fetchLines = array_keys(array_filter($commands, static fn (string $line): bool => preg_match('/\bgit fetch\b/', $line) === 1));
assert_same(0, preg_match('/\bgit pull\b/', $text . "\n" . implode("\n", $psCommands)), 'roteiro unico: sem git pull');
assert_same([$mergeLine], array_values(array_filter($mergeLines, static fn (int $i): bool => strpos($commands[$i], 'merge-base') === false)), 'roteiro unico: um merge so, o --ff-only do commit gravado');
assert_same(true, $recordLine >= 0 && $fetchLines !== [] && max($fetchLines) <= $recordLine, 'roteiro unico: todo fetch antes de gravar o commit (nenhum depois)');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; git fetch origin feat\/employee-form-rhf-zod && K=\$\(git rev-parse origin\/feat\/employee-form-rhf-zod\) && if \[ "\$K" = "<commit validado na estacao>" \]; then echo "\$K" \| tee \/var\/backups\/chronodesk\/deploy1-commit; else echo "PARE: [^"]+"; fi; unset K$/', $commands[$recordLine] ?? ''), 'roteiro unico: grava so o commit validado na estacao, do branch do deploy');
$branchLine = $first('[ "$B" = "codex/modernizacao-ui-backend" ]');
assert_same(true, $branchLine >= 0 && $branchLine < $recordLine, 'roteiro unico: confere o branch do servidor antes de gravar');
assert_same(true, $recordLine < $catFileLine && $catFileLine < $ancestorLine, 'roteiro unico: gravar, cat-file, conferencia de fast-forward');
assert_same(1, preg_match('/git merge-base --is-ancestor HEAD "\$D1" && echo "fast-forward OK: .*" \|\| echo "PARE: [^"]+"/', $commands[$ancestorLine] ?? ''), 'roteiro unico: HEAD nao ancestral do commit gravado = PARE');
// 0a (9.13.4): o codigo no ar e o 596db67 (ou ancestral do branch do deploy);
// a pre-condicao de banco e 015, 019 e manutencao_* ausentes, so contagens.
$base596 = '596db67c67a9cd84e53b28aaef4c3316191fe87c';
$headLine = $first('if [ "$H" = "' . $base596 . '" ]; then echo "HEAD no ar: 596db67 (OK)"; elif git merge-base --is-ancestor "$H" origin/feat/employee-form-rhf-zod; then');
$dbBeforeLine = $first("AS migration_015, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'manutencao\\_%') AS tabelas_manutencao");
assert_same(true, $headLine >= 0 && $headLine < $recordLine && strpos($commands[$headLine], 'else echo "PARE: ') !== false && $dbBeforeLine === $headLine + 1, 'roteiro unico: 0a confere o 596db67 (ou ancestral) e, logo depois, 015, 019 e manutencao_* ausentes');
assert_same(0, preg_match('/5d5c7c9/', $text . "\n" . implode("\n", $psCommands)), 'roteiro unico: nada mais supoe a fila antiga no ar (5d5c7c9)');
assert_same(true, strpos($prose, '`migration_015 = 0`, `tabela_019 = 0`, `tabelas_manutencao = 0`') !== false, 'roteiro unico: esperado do 0a com as tres ausencias');
assert_same(1, preg_match('/gitleaks git --redact --log-opts="' . $base596 . '\.\.origin\/feat\/employee-form-rhf-zod"/', implode("\n", $psCommands)), 'roteiro unico: gitleaks sobre tudo desde o 596db67');
// .htaccess alterado no servidor: regras conferidas no commit gravado (passo
// 2), copia antes (3a), descarte so no comando do merge (6b) e volta da copia
// no rollback de codigo (8b).
$htRulesLine = $first("grep -cxF -e 'RewriteBase /' -e 'RewriteRule ^var/www/chronodesk(/.*)?\$ /app/ [R=302,L]' -e 'RewriteRule ^chronodesk(/.*)?\$ /app/ [R=302,L]'");
$htCopyLine = $first('H=/var/backups/chronodesk/htaccess-local-antes-deploy1;');
assert_same(true, $htRulesLine > $ancestorLine && strpos($commands[$htRulesLine], 'grep -vxF --file=<(git show "$D1:.htaccess"') !== false, 'roteiro unico: regras locais do .htaccess conferidas no commit gravado, depois do fast-forward');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; H=\S+; if test -e "\$H"; then cmp -s \.htaccess "\$H" && echo "[^"]+" \|\| echo "PARE: [^"]+"; else cp -p \.htaccess "\$H" && cmp -s \.htaccess "\$H" && echo .* \|\| echo "PARE: [^"]+"; fi; unset H$/', $commands[$htCopyLine] ?? ''), 'roteiro unico: copia do .htaccess local conferida com cmp, sem sobrescrever outra');
// NN10 (aprovada em 2026-11-09, opcao 1): push do commit gravado para o branch
// codex no GitHub, pelo responsavel, no passo 9a; nunca --force.
$step9 = (int)strpos($runbook, "\n**9. ");
$pushLines = array_values(array_filter($psCommands, static fn (string $line): bool => preg_match('/\bgit push\b/', $line) === 1));
assert_same(true, strpos($prose, 'Nunca `merge` sem `--ff-only` no servidor') !== false && strpos($prose, 'decisao NN10 do responsavel, 2026-11-09') !== false && strpos($prose, 'o push e do responsavel (passo 9a)') !== false, 'roteiro unico: opcoes sem fast-forward e decisao NN10 registrada no passo 2');
assert_same(['git push origin <commit validado na estacao>:codex/modernizacao-ui-backend'], $pushLines, 'roteiro unico: um push so, do commit gravado para o branch codex (NN10)');
assert_same(true, $step9 > 0 && strpos($runbook, '9a. **Branch `codex/modernizacao-ui-backend` no GitHub (NN10') > $step9 && strpos($runbook, '```powershell' . "\n" . $pushLines[0]) > $step9, 'roteiro unico: o push fica no passo 9a, depois de um ciclo estavel');
assert_same(0, preg_match('/(^|\s)(--force|-f)(\s|$)/m', $text . "\n" . implode("\n", $psCommands)), 'roteiro unico: nenhum comando com --force');
assert_same(true, $mergeLine > $ancestorLine && $first('[ "$(git rev-parse HEAD)" = "$(cat /var/backups/chronodesk/deploy1-commit)" ] && echo "HEAD = deploy1-commit"') === $mergeLine + 1, 'roteiro unico: HEAD conferido logo depois do merge');

// ----------------------------------------------------------------------------
// 3. Rollback, APP_ENV, preflight e backup antes das migrations
// ----------------------------------------------------------------------------
$preDeployLine = $first('git rev-parse HEAD | tee /var/backups/chronodesk/pre-deploy-commit');
$appEnvLine = $first("sed -i 's/^APP_ENV=staging$/APP_ENV=production/' /var/www/.env");
$preflightLine = $first('bash -o pipefail -c \'git show "$D1:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env\'');
$backupLine = $first('mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B/database.sql.gz"');
$firstMigrationArchive = $first('git archive "$D1" migrations | tar -x -C "$E"');
$appCopyLine = $first('A=/var/backups/chronodesk/app-antes-deploy1; if test -e "$A"; then');
assert_same(true, $htRulesLine < $preDeployLine && $preDeployLine < $htCopyLine && $htCopyLine < $appCopyLine && $appCopyLine < $preflightLine && $preflightLine < $appEnvLine && $appEnvLine < $backupLine && $backupLine < $firstMigrationArchive, 'roteiro unico: ponto de rollback, copias do .htaccess e do app/, preflight do commit gravado ANTES do APP_ENV (C1) e backup, antes das migrations');
// C1 (9.13.7): o APP_ENV vale na hora (load_env() a cada requisicao), nao na
// recarga; o preflight do commit validado roda na vespera contra o .env real.
assert_same([false, true, true], [strpos($prose, 'O valor vale para o PHP-FPM na recarga do passo 6e') !== false, strpos($prose, 'O valor vale na hora, com o codigo anterior no ar') !== false, strpos($prose, '`sed -i \'s/^APP_ENV=production$/APP_ENV=staging/\' /var/www/.env` (vale na proxima requisicao, sem recarga)') !== false], 'roteiro unico: o APP_ENV vale na hora (C1), com a volta pronta');
$evePreflightLine = $first('bash -o pipefail -c \'git show "$K:scripts/preflight-deploy.sh" | sh -s -- /var/www/.env\'');
assert_same(true, $evePreflightLine > $first('case "$EV" in /root/ensaio-vespera.*)') + 1 &&$evePreflightLine < $recordLine && strpos($commands[$evePreflightLine], "export K='<commit validado na estacao>'; git cat-file -e \"\$K:scripts/preflight-deploy.sh\" && ") !== false && strpos($runbook, "\n0r. ") < strpos($runbook, $commands[$evePreflightLine]), 'vespera (0r): preflight do commit validado contra o /var/www/.env, depois do 0q e antes do dia (C1)');
// M1: o diretorio de backup existe antes de o passo 2 gravar nele.
assert_same(true, strpos($commands[$branchLine] ?? '', 'install -d -o root -g root -m 700 /var/backups/chronodesk && ') !== false && $branchLine < $recordLine, 'roteiro unico: /var/backups/chronodesk criado antes de gravar o commit (M1)');
// C2: copia do app/ antes do merge, conferida com diff -rq, sem sobrescrever.
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; A=\S+; if test -e "\$A"; then diff -rq app "\$A" && echo "[^"]+" \|\| echo "PARE: [^"]+"; else cp -a app "\$A" && diff -rq app "\$A" && echo .* \|\| echo "PARE: [^"]+"; fi; unset A$/', $commands[$appCopyLine] ?? ''), 'roteiro unico: copia do app/ com cp -a e diff -rq, sem sobrescrever outra (C2)');
// Backup do dia: a rotina do 072ed16 nao esta no servidor; dump do root com
// pipefail, gzip -t, estado e SHA256SUMS, ou PARE.
assert_same(true, strpos($commands[$backupLine] ?? '', "read -rsp 'Senha root do MySQL: ' P;") !== false && strpos($commands[$backupLine], 'install -d -o root -g root -m 700 "$B" && bash -o pipefail -c') !== false && strpos($commands[$backupLine], '&& gzip -t "$B/database.sql.gz" && ') !== false && strpos($commands[$backupLine], 'sha256sum database.sql.gz estado.tar.gz > SHA256SUMS') !== false && strpos($commands[$backupLine], '|| echo "PARE: backup falhou') !== false, 'roteiro unico: backup do dia com pipefail, gzip -t e SHA256SUMS, ou PARE');
assert_same(0, preg_match('/systemctl start chronodesk-backup/', $text), 'roteiro unico: o dia nao depende da rotina de backup (nao instalada no 596db67)');
assert_same(1, preg_match('/^K=\/var\/backups\/chronodesk\/env-antes-app-env-[^;]+; if install -m 600 \/var\/www\/\.env "\$K"; then [^;]*; sed -i .*; else echo "PARE: copia do \.env falhou, nada alterado"; fi; unset K$/', $commands[$appEnvLine] ?? ''), 'roteiro unico: copia do .env (600) antes de trocar o APP_ENV');
assert_same(true, strpos($commands[$preflightLine] ?? '', 'git cat-file -e "$D1:scripts/preflight-deploy.sh" && ') !== false, 'roteiro unico: preflight com cat-file antes');

// ----------------------------------------------------------------------------
// 4. Migrations do deploy 1, em ordem, com ensaio e verificacao; nada do F8/F9
// ----------------------------------------------------------------------------
$applyLines = [];
foreach ($commands as $i => $line) {
    if (preg_match('/bash -o pipefail -c \'git show "\$D1:migrations\/(2026\d{4}_\d{3}[a-z]?_[a-z0-9_]+\.sql)" \| docker exec -i -e MYSQL_PWD="\$P" Chrono_Desk_DB mysql -uroot sistema_pausas\'/', $line, $m) === 1) {
        $applyLines[$m[1]] = $i;
    }
}
// Ordem da fila antiga (9.13.4): 015, 016, 017, 011, 014 e 019 antes do
// codigo; 012 com o merge; 016b depois (o codigo anterior leria lideranca
// como tecnico).
$deploy1Migrations = ['20261001_015_login_user_throttle.sql', '20261002_016_lideranca_profile.sql', '20261002_017_shift_attachment_team.sql', '20261005_011_schedule_history.sql', '20261013_014_employee_archive.sql', '20261013_019_presence_cutoff.sql', '20261007_012_absences.sql', '20261002_016b_lideranca_profile_data.sql'];
assert_same($deploy1Migrations, array_keys($applyLines), 'roteiro unico: migrations aplicadas 015, 016, 017, 011, 014, 019, 012 e 016b, nesta ordem');
$applyIndexes = array_values($applyLines);
$sortedApplyIndexes = $applyIndexes;
sort($sortedApplyIndexes);
assert_same($sortedApplyIndexes, $applyIndexes, 'roteiro unico: ordem das linhas de aplicacao');
assert_same($mergeLine, $applyLines['20261007_012_absences.sql'], 'roteiro unico: a 012 e o merge no mesmo comando (decisao 3 do F3)');
assert_same(true, $applyLines['20261002_016b_lideranca_profile_data.sql'] > $mergeLine, 'roteiro unico: a 016b so depois do merge');
foreach ($applyLines as $file => $i) {
    assert_same(true, strpos($commands[$i], "git cat-file -e \"\$D1:migrations/{$file}\" && bash -o pipefail -c") !== false && strpos($commands[$i], "read -rsp 'Senha root do MySQL: ' P;") !== false, "roteiro unico: {$file} com cat-file e pipefail");
}
// Toda migration do repositorio posterior ao 596db67 (a 010 e a ultima dele;
// datas a partir de 2026-10-01) esta no roteiro; migration nova (018, 020)
// reprova ate o roteiro decidir. O registro do corte nao e migration de
// esquema.
$repoMigrations = array_values(array_filter(array_map('basename', glob($root . '/migrations/2026*.sql') ?: []), static fn (string $name): bool => substr($name, 0, 8) >= '20261001' && strpos($name, '_registrar_corte') === false));
sort($repoMigrations);
$expectedSorted = $deploy1Migrations;
sort($expectedSorted);
assert_same($expectedSorted, $repoMigrations, 'repositorio: as migrations da Parte B sao as do deploy 1 (nova migration exige o roteiro)');
assert_same(0, preg_match('/_(013|018|020)_/', str_replace("grep -cE '_(013|018|020)_'", '', $text)), 'roteiro unico: nenhum comando com a 013, a 018 ou a 020');
assert_same(true, $first("grep -cE '_(013|018|020)_'") > $catFileLine && $first("grep -cE '_(013|018|020)_'") < $firstMigrationArchive, 'roteiro unico: conferencia de que o commit nao traz 013, 018 nem 020');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; export D1=\$\(cat \/var\/backups\/chronodesk\/deploy1-commit\); E=\$\(mktemp -d \/root\/ensaio-deploy1\.XXXXXX\); export E; bash -o pipefail -c \'git archive "\$D1" migrations \| tar -x -C "\$E"\' && echo "extraido em \$E" \|\| echo "PARE: [^"]+"$/', $commands[$firstMigrationArchive] ?? ''), 'roteiro unico: no dia, so as migrations extraidas do commit gravado (sem script de ensaio), com pipefail');
// O "antes" de todas as migrations no dia (RR7 revista, 9.13.6): o mesmo
// comando do 0b, logo depois da extracao e antes da 1a aplicacao.
$beforeLines = $all('AS access_role_005,');
assert_same(true, count($beforeLines) === 2 && $beforeLines[0] < $recordLine && $beforeLines[1] === $firstMigrationArchive + 1 && $beforeLines[1] < min($applyLines) && $commands[$beforeLines[0]] === $commands[$beforeLines[1]], 'roteiro unico: o 0b de novo no dia, igual, logo antes da 1a migration');
assert_same(0, preg_match('/(^|[\s;])bash scripts\/ensaio-migration\.sh/m', $text), 'roteiro unico: ensaio so com o script do commit gravado (o do servidor e anterior ao F1)');
// Os 14 ensaios na vespera (0q), depois do 0n, sobre o commit validado na
// estacao extraido para /root/ensaio-vespera; nenhum ensaio no dia.
$eveExtractLine = $first('EV=$(mktemp -d /root/ensaio-vespera.XXXXXX)');
assert_same(1, preg_match('/^cd \/var\/www\/chronodesk; export K=\'<commit validado na estacao>\'; EV=\$\(mktemp -d \/root\/ensaio-vespera\.XXXXXX\); export EV; git cat-file -e "\$K\^\{commit\}" && bash -o pipefail -c \'git archive "\$K" migrations scripts\/ensaio-migration\.sh \| tar -x -C "\$EV"\' && echo "extraido em \$EV .*" \|\| echo "PARE: [^"]+"; unset K$/', $commands[$eveExtractLine] ?? ''), 'vespera: o commit validado na estacao extraido com cat-file e pipefail');
$ensaios = ['20261001_015_login_user_throttle.sql' => ['d1_015 '], '20261002_016_lideranca_profile.sql' => ['d1_016 ', 'd1_016b '], '20261002_016b_lideranca_profile_data.sql' => ['d1_016b '], '20261002_017_shift_attachment_team.sql' => ['d1_017 '], '20261005_011_schedule_history.sql' => ['d1_011 '], '20261013_014_employee_archive.sql' => ['d1_014 ', 'd1_014_dados ', 'd1_014_rollback '], '20261013_019_presence_cutoff.sql' => ['d1_019 ', 'd1_019_registro ', 'd1_019_passado '], '20261007_012_absences.sql' => ['d1_012 ', 'd1_012_parada ', 'd1_012_rollback ']];
foreach ($ensaios as $file => $variants) {
    foreach ($variants as $variant) {
        $line = $first('bash "$EV/scripts/ensaio-migration.sh" ' . $variant);
        assert_same(true, $line > $eveExtractLine && $line < $recordLine && $line < $applyLines[$file], "vespera: ensaio {$variant}na vespera, antes do dia ({$file})");
    }
}
$checkFiles = ['20261005_011_schedule_history.sql' => '20261005_011_schedule_history_check.sql', '20261013_014_employee_archive.sql' => '20261013_014_employee_archive_check.sql', '20261013_019_presence_cutoff.sql' => '20261013_019_presence_cutoff_check.sql'];
foreach ($checkFiles as $file => $check) {
    assert_same(true, strpos($commands[$applyLines[$file] + 1] ?? '', "< <(cat \"\$E/migrations/verificacao/{$check}\"); unset P") !== false, "roteiro unico: verificacao logo depois de {$file}");
}
$inlineChecks = ['20261001_015_login_user_throttle.sql' => "TABLE_NAME = 'login_user_throttle') AS migration_015", '20261002_016_lideranca_profile.sql' => "INDEX_NAME = 'uq_funcionarios_ad_login') AS uq_ad_login", '20261002_017_shift_attachment_team.sql' => "COLUMN_NAME = 'team') AS migration_017", '20261002_016b_lideranca_profile_data.sql' => 'FROM funcionarios_perfil_016b GROUP BY passo'];
foreach ($inlineChecks as $file => $needle) {
    assert_same(true, strpos($commands[$applyLines[$file] + 1] ?? '', $needle) !== false, "roteiro unico: verificacao logo depois de {$file}");
}
$check012Line = $first('< migrations/verificacao/20261007_012_absences_check.sql; unset P');
assert_same(true, $check012Line > $mergeLine && $check012Line < $applyLines['20261002_016b_lideranca_profile_data.sql'], 'roteiro unico: verificacao da 012 depois do merge');
$q4Line = $first('SELECT COUNT(*) AS ausencias FROM portal_absences;');
$qf4Line = $first('AS excecoes_de_inativos;');
$check019Before = $first('< <(cat "$E/migrations/verificacao/20261013_019_presence_cutoff_check.sql"); unset P');
assert_same(true, $q4Line > $applyLines['20261013_019_presence_cutoff.sql'] && $q4Line < $mergeLine && $qf4Line < $applyLines['20261013_014_employee_archive.sql'] && $qf4Line > $applyLines['20261005_011_schedule_history.sql'] && $check019Before > $firstMigrationArchive && $check019Before < $applyLines['20261013_019_presence_cutoff.sql'], 'roteiro unico: Q4 antes da 012, Q-F4 antes da 014, verificacao da 019 antes dela');
$verifyLoop = $first('for v in 20261005_011_schedule_history_check 20261007_012_absences_check 20261013_014_employee_archive_check 20261013_019_presence_cutoff_check; do');
assert_same(true, $verifyLoop > $mergeLine, 'roteiro unico: as quatro verificacoes de novo no pos-deploy');

// ----------------------------------------------------------------------------
// 5. Ensaio do codigo: /tmp/.env, pipefail, www-data, todos os QAs PHP
// ----------------------------------------------------------------------------
$tmpEnvLine = $first('[ ! -e /tmp/.env ] && echo "/tmp/.env ausente: OK" || echo "PARE:');
$qaArchiveLine = $first('Q=$(mktemp -d /tmp/ensaio-deploy1-qa.XXXXXX)');
$lintLine = $first('find "$Q" -name \'*.php\'');
$qaLoopLine = $first('for q in ');
$qaCleanupLine = $first('case "$Q" in /tmp/ensaio-deploy1-qa.*) rm -r "$Q"');
assert_same(true, $applyLines['20261013_019_presence_cutoff.sql'] < $tmpEnvLine && $tmpEnvLine < $qaArchiveLine && $qaArchiveLine < $lintLine && $lintLine < $qaLoopLine && $qaLoopLine < $qaCleanupLine && $qaCleanupLine < $mergeLine, 'roteiro unico: ensaio do codigo depois das migrations 011, 014 e 019 e antes do merge');
assert_same(1, preg_match('/bash -o pipefail -c \'git archive "\$D1" \| tar -x -C "\$Q"\' && chown -R www-data: "\$Q" && echo "extraido em \$Q"/', $commands[$qaArchiveLine] ?? ''), 'roteiro unico: commit inteiro extraido com pipefail e passado so ao www-data');
assert_same([$qaArchiveLine], array_keys(array_filter($commands, static fn (string $line): bool => preg_match('/\b(chown|chmod)\b/', $line) === 1)), 'roteiro unico: permissao so do diretorio do ensaio de codigo');
assert_same(true, strpos($commands[$qaLoopLine] ?? '', '[ ! -e /tmp/.env ] && for q in ') === 0 && strpos($commands[$qaLoopLine], 'runuser -u www-data -- php "$Q/scripts/$q.php"') !== false, 'roteiro unico: QAs so sem /tmp/.env, como www-data, com php arquivo.php');
preg_match('/for q in ([a-z0-9 -]+); do/', $commands[$qaLoopLine] ?? '', $qaList);
$runbookQas = explode(' ', trim($qaList[1] ?? ''));
$repoQas = array_map(static fn (string $path): string => basename($path, '.php'), glob($root . '/scripts/qa-*.php') ?: []);
$sortedRunbookQas = $runbookQas;
sort($sortedRunbookQas);
sort($repoQas);
assert_same($repoQas, $sortedRunbookQas, 'roteiro unico: o ensaio roda todos os QAs PHP do repositorio (QA novo exige o roteiro)');
assert_same(1, preg_match('/^case "\$E" in \/root\/ensaio-deploy1\.\*\) rm -r "\$E" && echo "[^"]+";; \*\) echo "PARE: [^"]+";; esac; unset E D1$/', $commands[$first('case "$E" in /root/ensaio-deploy1.*)')] ?? ''), 'roteiro unico: limpeza do ensaio das migrations so do proprio diretorio');
$maintenanceOffLine = $first('[ -e /var/www/chronodesk.maintenance ] && rm -- /var/www/chronodesk.maintenance;');
$eveCleanupLine = $first('case "$EV" in /root/ensaio-vespera.*)');
assert_same(1, preg_match('/^case "\$EV" in \/root\/ensaio-vespera\.\*\) rm -r "\$EV" && echo "[^"]+";; \*\) echo "PARE: [^"]+";; esac; unset EV$/', $commands[$eveCleanupLine] ?? ''), 'vespera: limpeza do ensaio so do proprio diretorio');
assert_same([$eveCleanupLine, $qaCleanupLine, $maintenanceOffLine, $first('case "$E" in /root/ensaio-deploy1.*)')], array_keys(array_filter($commands, static fn (string $line): bool => preg_match('/\brm\b/', $line) === 1)), 'roteiro unico: so quatro remocoes: a extracao da vespera, o ensaio de codigo e a extracao do dia (guardadas por case) e o arquivo-sinal da manutencao (so ele, conferido depois)');

// ----------------------------------------------------------------------------
// 6. Q-F6, Q-F7, base do criterio, build e recarga
// ----------------------------------------------------------------------------
$qf6Line = $first('AND f.fora_mapa_escala = 1) AS vinculos_fora_do_mapa');
$qf7Line = $first('fora_mapa_escala = 1) AS fora_do_mapa');
$labelLines = $all('AS rotulo_que_o_php_nao_reconhece');
$baseLines = $all('bash <(git show "$D1:scripts/medir-polling.sh")');
$referenceLines = $all('bash <(git show origin/feat/employee-form-rhf-zod:scripts/medir-polling.sh)');
assert_same(true, $qf6Line > $qaCleanupLine && $qf6Line < $mergeLine && $qf7Line > $qaCleanupLine && $qf7Line < $mergeLine && count(array_filter($labelLines, static fn (int $i): bool => $i > $qaCleanupLine && $i < $mergeLine)) === 1, 'roteiro unico: Q-F6 e Q-F7 completas e rotulo imediatamente antes do merge');
assert_same([2, 2], [count(array_filter($referenceLines, static fn (int $i): bool => $i < $recordLine)), count(array_filter($baseLines, static fn (int $i): bool => $i > $qaCleanupLine && $i < $mergeLine))], 'roteiro unico: referencia antes do dia (branch remoto) e base do criterio do commit gravado, antes do merge');
foreach (array_merge($referenceLines, $baseLines) as $i) {
    assert_same(1, preg_match('/git cat-file -e (origin\/feat\/employee-form-rhf-zod:|"\$D1:)scripts\/medir-polling\.sh"? && (URL=http:\/\/127\.0\.0\.1\/api\/portal\/dashboard\.php )?bash <\(git show/', $commands[$i]), 'roteiro unico: medicao com cat-file antes: ' . substr($commands[$i], 0, 60));
}
$buildLine = $first('cd /var/www/chronodesk/frontend && npm ci && npm run build');
$reloadLine = $first('systemctl reload "php$(php -r');
assert_same(true, $buildLine > $mergeLine && $reloadLine === $buildLine + 1, 'roteiro unico: build e recarga do PHP-FPM depois do merge');
// BIZ-02 (9.13.4): manutencao ligada antes do merge (a regra entra com o
// .htaccess novo), descarte do .htaccess local so no comando do merge, depois
// dele o corte (biz02_01) antes de qualquer gravacao do codigo novo: antes da
// 016b, da recarga, da validacao, da reabertura e do registro do corte da 019.
$maintenanceOnLine = $first('touch /var/www/chronodesk.maintenance;');
$maintenanceSeenLine = $first("'portal visto de fora (manutencao): %{http_code}");
$biz02Line = $first('< migrations/correcao_dados/biz02_01_registrar_corte.sql; unset P');
$healthInMaintenance = $first("curl -s -o /dev/null -w 'health: %{http_code}");
$timezoneDbLine = $first('runuser -u www-data -- php scripts/qa-timezone.php --db');
assert_same($mergeLine - 1, $maintenanceOnLine, 'roteiro unico: arquivo-sinal da manutencao criado logo antes do comando do merge');
assert_same(1, preg_match('/ && bash -o pipefail -c \'git show "\$D1:migrations\/20261007_012_absences\.sql" \| [^\']+\' && git checkout -- \.htaccess && git merge --ff-only "\$D1"; unset P$/', $commands[$mergeLine] ?? ''), 'roteiro unico: 012, descarte do .htaccess local e merge, cada um so se o anterior der certo');
assert_same([1, 1], [count($all('git checkout -- .htaccess')), count(array_filter($all('git checkout -- .htaccess'), static fn (int $i): bool => $i === $mergeLine))], 'roteiro unico: o .htaccess local so e descartado no comando do merge');
// A2 e risco 5 (9.13.7): logo depois do merge (quando a manutencao passa a
// valer), espera de 10 s e o segundo dump, com o portal parado; so entao o
// corte do BIZ-02.
$maintenanceDumpLine = $first('mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF sistema_pausas | gzip | tee "$B2/database.sql.gz"');
assert_same(true, $maintenanceSeenLine === $mergeLine + 2 && $maintenanceDumpLine === $mergeLine + 3 && $biz02Line === $mergeLine + 4, 'roteiro unico: manutencao vista de fora, espera e backup em manutencao, e o corte do BIZ-02 logo depois do merge');
assert_same(true, strpos($commands[$maintenanceDumpLine] ?? '', 'sleep 10; install -d -o root -g root -m 700 "$B2" && bash -o pipefail -c') !== false && strpos($commands[$maintenanceDumpLine], '&& gzip -t "$B2/database.sql.gz" && ') !== false && strpos($commands[$maintenanceDumpLine], 'sha256sum database.sql.gz estado.tar.gz > SHA256SUMS') !== false && strpos($commands[$maintenanceDumpLine], '|| echo "PARE: backup em manutencao falhou') !== false, 'roteiro unico: espera de 10 s e dump em manutencao com pipefail, gzip -t e SHA256SUMS, ou PARE (A2)');
assert_same(true, $biz02Line < $applyLines['20261002_016b_lideranca_profile_data.sql'] && $applyLines['20261002_016b_lideranca_profile_data.sql'] < $buildLine && $reloadLine < $healthInMaintenance && $healthInMaintenance < $timezoneDbLine && $timezoneDbLine < $maintenanceOffLine && $maintenanceOffLine < $first('for v in 20261005_011_schedule_history_check'), 'roteiro unico: corte do BIZ-02, 016b, build, recarga, validacao em manutencao e so entao a reabertura');
// C3 (9.13.7): teste de fumaca pelo loopback, depois do QA do fuso e antes
// da reabertura; o script e versionado e so imprime codigos e contagens.
$smokeLine = $first("cd /var/www/chronodesk; env PHPLOG='<log de erros do PHP>' bash scripts/smoke-deploy1.sh '<ServerName do vhost>'");
assert_same(true, $smokeLine > $timezoneDbLine && $smokeLine < $maintenanceOffLine && strpos($prose, 'na ultima linha, `FUMACA OK`') !== false && strpos($prose, 'Teste de fumaca do passo 6f sem **`FUMACA OK`** na ultima linha: nao reabra') !== false, 'roteiro unico: teste de fumaca entre o 6f e a reabertura, com a regra de parada (C3)');
$smoke = is_file($root . '/scripts/smoke-deploy1.sh') ? str_replace("\r\n", "\n", (string)file_get_contents($root . '/scripts/smoke-deploy1.sh')) : '';
assert_same([true, true, true, true, true, true, true, 0], [
    strpos($smoke, "read -rsp 'Senha do AD: ' SMK_S") !== false,
    strpos($smoke, "trap 'rm -rf -- \"\${W:?}\"' EXIT") !== false,
    strpos($smoke, '--data-binary @-') !== false && strpos($smoke, '-H @"$HDR"') !== false,
    strpos($smoke, 'for U in api/status.php api/portal/schedules.php api/portal/pa_map.php api/portal/absences.php; do') !== false,
    strpos($smoke, '"action" => "create"') !== false && strpos($smoke, '"action" => "cancel"') !== false,
    strpos($smoke, "grep -vc '\\[AUTH_AD\\] Login bem-sucedido'") !== false,
    strpos($smoke, 'echo "FUMACA OK"') !== false,
    preg_match('/^[^\n#]*\bcurl\b[^\n]*(\s-u\s|--user|\s-d\s|--data\s|--data-raw|SMK_S)/m', str_replace("\\\n", ' ', $smoke)),
], 'smoke-deploy1.sh: senha sem eco e fora do argv, cookie e token em diretorio temporario apagado, os quatro GET, lancar e cancelar, log do PHP e FUMACA OK');
assert_same([1, 0, 0], [count($all('migrations/correcao_dados/biz02_01_registrar_corte.sql; unset P')), count(array_filter($commands, static fn (string $line, int $i): bool => $i > $recordLine && preg_match('/biz02_0[234]/', $line) === 1, ARRAY_FILTER_USE_BOTH)), preg_match('/\bSET @ignorar_guardas\b/', $text)], 'roteiro unico: no dia, so o script 1 do BIZ-02, sem ignorar guardas por comando');
assert_same(true, strpos($prose, 'Corte do BIZ-02 com **`ABORTADO PELAS GUARDAS`** (passo 6c): pare') !== false, 'roteiro unico: regra de parada das guardas do BIZ-02');
// Ensaio do BIZ-02 antes do dia (0n), na copia sistema_pausas_ensaio que o
// responsavel criou em 2026-11-09 do backup pre-dia: conferencia da copia
// (33 tabelas, sem manutencao_*, sem gatilho, rotina ou evento), os seis
// scripts na ordem da secao 7.3 so nela, e a conferencia depois. A copia nao
// e criada nem apagada no 0n: o passo 4 le dela e o 7m a apaga (LGPD).
$copyName = 'sistema_pausas_ensaio';
$biz02CopyCheckLine = $first("TABLE_SCHEMA = '{$copyName}') AS copia UNION ALL SELECT 'manutencao_*'");
$biz02LoopLine = $first('for S in biz02_01_registrar_corte biz02_02_verificar biz02_03_corrigir biz02_02_verificar biz02_04_rollback biz02_02_verificar; do');
$biz02AfterLine = $first('AS manutencao_na_copia, (SELECT COUNT(*) FROM sistema_pausas_ensaio.manutencao_marcadores');
assert_same(true, $biz02CopyCheckLine >= 0 && $biz02CopyCheckLine < $biz02LoopLine && $biz02AfterLine === $biz02LoopLine + 1 && $biz02AfterLine < $recordLine, 'roteiro unico: ensaio do BIZ-02 antes do dia, com a copia conferida antes e depois');
assert_same(true, strpos($commands[$biz02CopyCheckLine] ?? '', "information_schema.EVENTS WHERE EVENT_SCHEMA = '{$copyName}'") !== false && strpos($commands[$biz02CopyCheckLine], "information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$copyName}'") !== false && strpos($commands[$biz02CopyCheckLine], "information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '{$copyName}'") !== false, 'roteiro unico: a copia conferida sem gatilho, rotina nem evento (o backup leva --events)');
assert_same(true, strpos($commands[$biz02LoopLine] ?? '', "mysql -uroot --table {$copyName}'") !== false && preg_match('/--table sistema_pausas\'/', $commands[$biz02LoopLine]) === 0, 'roteiro unico: scripts do BIZ-02 do ensaio so na copia, nunca na producao');
assert_same(0, preg_match('/ensaio_biz02|CREATE DATABASE/', $text), 'roteiro unico: o roteiro nao cria banco (a copia do ensaio ja existe)');
// Ensaios das migrations (passo 4) com ORIGEM na copia, nunca na producao.
$ensaioLines = $all('bash "$EV/scripts/ensaio-migration.sh" d1_');
assert_same([14, 14], [count($ensaioLines), count(array_filter($ensaioLines, static fn (int $i): bool => strpos($commands[$i], "env ORIGEM={$copyName} ") !== false))], 'vespera: os 14 ensaios leem da copia (ORIGEM)');
assert_same(count($all('scripts/ensaio-migration.sh"')) - 1, count($ensaioLines), 'roteiro unico: nenhum ensaio fora da lista (o outro e o cat-file do passo 2)');
assert_same(true, min($ensaioLines) > $biz02AfterLine && max($ensaioLines) < $eveCleanupLine && $eveCleanupLine < $recordLine, 'vespera: os ensaios das migrations depois do 0n e antes do dia');
assert_same(0, preg_match('/ensaio-migration\.sh" d1_/', implode("\n", array_slice($commands, $recordLine))), 'roteiro unico: nenhum ensaio no dia (RR7 revista)');
// Copia apagada uma vez, no 7m, com confirmacao pelo nome, depois de todos os
// ensaios; nenhum outro DROP DATABASE no roteiro.
$copyDropLines = $all("DROP DATABASE IF EXISTS {$copyName};");
$migrationsCleanupLine = $first('case "$E" in /root/ensaio-deploy1.*)');
assert_same(true, count($copyDropLines) === 1 && $copyDropLines[0] === $eveCleanupLine + 1 && $copyDropLines[0] > max($ensaioLines) && $copyDropLines[0] < $recordLine, 'vespera: copia do ensaio apagada no fim do 0q, depois de todos os ensaios');
assert_same(true, strpos($commands[$migrationsCleanupLine + 1] ?? '', "SELECT COUNT(*) AS copia_restante FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$copyName}';") !== false && strpos($commands[$migrationsCleanupLine + 1], 'DROP') === false, 'roteiro unico: o 7m so confere que a copia nao existe mais');
assert_same(1, preg_match('/^read -rp \'[^\']+\' NC; if \[ "\$NC" = "sistema_pausas_ensaio" \]; then read -rsp \'Senha root do MySQL: \' P; echo; docker exec [^;]+ -e "DROP DATABASE IF EXISTS sistema_pausas_ensaio; SELECT [^"]+"; unset P; else echo "nada apagado[^"]*"; fi; unset NC$/', $commands[$copyDropLines[0] ?? -1] ?? ''), 'roteiro unico: DROP da copia so com o nome digitado');
assert_same(1, preg_match_all('/DROP DATABASE/', $text), 'roteiro unico: um DROP DATABASE so (o da copia)');
assert_same(true, strpos($prose, 'os 14 ensaios das migrations (0q) como esperado, sobre o mesmo commit validado, com a copia apagada (`copia_restante = 0`)') !== false && strpos($prose, '`/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz`') !== false && strpos($prose, 'banco `sistema_pausas` com **1,8 MB**') !== false, 'roteiro unico: dados do servidor de 2026-11-09 e passo 1 com a copia presente');
assert_same(true, strpos($prose, 'o backup pre-dia (`/root/backups_chronodesk/pre_deploy1_20261109_1549.sql.gz`, com dados pessoais') > strpos($prose, '9e. **Limpeza do que o deploy deixou, com aprovacao:**'), 'roteiro unico: o backup pre-dia (dados pessoais) entra na limpeza do 9e');
// Espaco livre com limite minimo: no 0m (antes do dia) e no 3d, logo antes do
// backup e antes do npm ci; o mesmo comando.
$spaceLines = $all('for LIM in "/var/www/chronodesk:500" "$NPMC:200" "/var/backups:100" "$DROOT:200" "/tmp:50" "/root:50"; do');
assert_same(true, count($spaceLines) === 2 && $spaceLines[0] < $recordLine && $spaceLines[1] === $backupLine - 1 && $spaceLines[1] > $preflightLine && $commands[$spaceLines[0]] === $commands[$spaceLines[1]], 'roteiro unico: espaco livre no 0m e no 3d, o mesmo comando, logo antes do backup');
assert_same(true, strpos($commands[$spaceLines[0]] ?? '', '|| echo "PARE: espaco livre abaixo do minimo"') !== false && strpos($commands[$spaceLines[0]], 'NPMC=$(npm config get cache)') !== false, 'roteiro unico: espaco abaixo do minimo da PARE; o cache do npm e o do root');
assert_same(true, strpos($prose, 'O `npm ci` apaga o `node_modules` antigo antes de instalar, mas o minimo nao conta com isso.') !== false && strpos($prose, '`node_modules` com **114 MB**') !== false, 'roteiro unico: espaco do npm ci e do build dito no 0m');

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
$switchLine = $first("sed -i 's/^STATUS_PRESENCE_ENABLED=.*/STATUS_PRESENCE_ENABLED=false/' /var/www/.env");
assert_same(1, preg_match('/^K=\/var\/backups\/chronodesk\/env-antes-interruptor-[^;]+; if install -m 600 \/var\/www\/\.env "\$K"; then [^;]*; if grep -q .*sed -i .*; fi; else echo "PARE: copia do \.env falhou, nada alterado"; fi; unset K$/', $commands[$switchLine] ?? ''), 'roteiro unico: desligamento com copia do .env (600) antes do sed');
assert_same(true, $switchLine > $registerLine && $first("grep -rn 'STATUS_PRESENCE_ENABLED' /etc/php/*/fpm/") === $switchLine - 1 && strpos($commands[$switchLine + 1] ?? '', 'preflight-deploy.sh /var/www/.env | grep STATUS_PRESENCE_ENABLED') !== false && strpos($commands[$switchLine + 2] ?? '', 'systemctl reload "php$(php -r') === 0, 'roteiro unico: pool do PHP-FPM, copia, preflight e recarga');
$listCopies = $first("find /var/backups/chronodesk -maxdepth 1 -type f \\( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \\) -printf");
$deleteCopies = $first("find /var/backups/chronodesk -maxdepth 1 -type f \\( -name 'env-antes-interruptor-*' -o -name 'env-antes-app-env-*' \\) -print -delete");
assert_same(true, $listCopies > $switchLine && $deleteCopies === $listCopies + 1 && count($all('-delete')) === 1, 'roteiro unico: limpeza das copias env-antes-* (listar, depois apagar), so elas');
assert_same(true, strpos($prose, 'Depois de conferidas e **com aprovacao do responsavel**') !== false, 'roteiro unico: limpeza das copias so com aprovacao');
$rollbackOrder = [];
foreach ($commands as $i => $line) {
    if (preg_match('/git show "\$D1:migrations\/rollback\/(2026\d{4}_(\d{3})_[a-z_]+_down\.sql)" .* && echo "rollback da (\d{3}) aplicado"/', $line, $m) === 1 && $m[2] === $m[3]) {
        $rollbackOrder[] = $m[2];
    }
}
$checkoutLine = $first('git checkout --detach "$(cat /var/backups/chronodesk/pre-deploy-commit)"');
$cycleDownLine = $first('git show "$D1:migrations/rollback/20261007_f2b_cycle_down.sql"');
assert_same(['019', '014', '012', '011', '017', '016', '015'], $rollbackOrder, 'roteiro unico: rollback das migrations na ordem inversa, lido do commit gravado');
$down016bLine = $first('git show "$D1:migrations/rollback/20261002_016b_lideranca_profile_data_down.sql"');
$biz02UndoLine = $first("DROP TABLE IF EXISTS manutencao_biz02_datetime; DELETE FROM manutencao_marcadores WHERE chave LIKE 'biz02%';");
// C2 e A3 (9.13.7): no degrau 2 o app/ volta da copia do 3a, sem npm (o
// build retirado vai para /var/backups, nada e apagado); a fotografia do
// BIZ-02 so sai se a correcao historica nao rodou.
$appRestoreLine = $first('A=/var/backups/chronodesk/app-antes-deploy1; X=/var/backups/chronodesk/app-deploy1-retirado-');
assert_same(true, $appRestoreLine === $checkoutLine + 1 && strpos($commands[$appRestoreLine], '{ ! test -e app || mv app "$X"; } && cp -a "$A" app && diff -rq "$A" app && ') !== false && count(array_filter($all('npm ci'), static fn (int $i): bool => $i > $checkoutLine)) === 0, 'roteiro unico: degrau 2 volta o app/ da copia, sem npm ci (C2)');
assert_same(true, strpos($commands[$biz02UndoLine] ?? '', "SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_correcao';\"); if [ \"\$X\" = \"0\" ]; then ") !== false && strpos($commands[$biz02UndoLine], "if [ \"\$X\" = \"0\" ]") < strpos($commands[$biz02UndoLine], 'DROP TABLE IF EXISTS manutencao_biz02_datetime'), 'roteiro unico: o DROP da fotografia do BIZ-02 so sem a correcao historica (A3)');
assert_same(true, strpos($prose, 'Se o `npm ci` falhar por rede') !== false && strpos($prose, 'o degrau 2 tambem nao resolve') === false, 'roteiro unico: 6e com PARE para falha de rede e degrau 2 sem npm (C2)');
assert_same(true, $cycleDownLine > $switchLine && $cycleDownLine < $down016bLine && $down016bLine < $checkoutLine && $checkoutLine < $biz02UndoLine && $biz02UndoLine < $first('rollback da 019 aplicado'), 'roteiro unico: ciclo do F2B e 016b com o codigo novo no ar, depois o codigo, o corte do BIZ-02 e so entao as migrations');
assert_same(true, strpos($commands[$checkoutLine] ?? '', '&& cp -p /var/backups/chronodesk/htaccess-local-antes-deploy1 .htaccess && ') !== false, 'roteiro unico: o rollback de codigo devolve o .htaccess local (sem ele, o 596db67 fica sem o RewriteBase)');
foreach (['**Risco LGPD de voltar o codigo**', '**F3:**', '**SEC-07b:**', '**DF7 (F6):**', '**DV9 a DV11 (F7):**', '**Degrau 2: codigo (so com aprovacao previa do responsavel).**', '**Degrau 3: migrations (so com aprovacao, so com as tabelas vazias ou com a copia de seguranca que o proprio rollback faz)**'] as $needle) {
    assert_same(true, strpos($prose, $needle) !== false, "roteiro unico: rollback em degraus com {$needle}");
}

// ----------------------------------------------------------------------------
// 9. So contagens; senha sem eco e apagada
// ----------------------------------------------------------------------------
// Unica mencao a ad_login: a contagem de repetidos do 0b (pre-condicao da 016),
// que o dia repete no 4a (o mesmo comando).
$adLoginCount = '(SELECT COUNT(*) FROM (SELECT ad_login FROM funcionarios WHERE ad_login IS NOT NULL GROUP BY ad_login HAVING COUNT(*) > 1) d) AS ad_login_repetidos';
assert_same(2, substr_count($text, $adLoginCount), 'roteiro unico: contagem de login AD repetido (0b e o 0b de novo no 4a), so contagem');
assert_same(0, preg_match('/\b(f\.nome|nome AS|SELECT nome|ad_login|registered_by|employee_name|employee_login|linha_chave)\b/', str_replace($adLoginCount, '', $text)), 'roteiro unico: nenhuma consulta devolve nome, login ou autor');
assert_same(0, preg_match('/\s-p(?!rint|ath)\S/', $text), 'roteiro unico: nenhuma senha em -p na linha de comando');
$passwordLines = $all('MYSQL_PWD="$P"');
assert_same(true, count($passwordLines) >= 30, 'roteiro unico: consultas ao MySQL presentes');
foreach ($passwordLines as $i) {
    $line = $commands[$i];
    assert_same(true, strpos($line, "read -rsp 'Senha root do MySQL: ' P;") !== false && preg_match('/unset P( U)?(;|$| done$)/', rtrim($line)) === 1 && preg_match('/\s-p\S/', $line) === 0, 'roteiro unico: senha lida sem eco e apagada na mesma linha: ' . substr($line, 0, 60));
}
$secretLines = $all('SECRET_KEY');
$secretLine = 'awk -F= \'/^APP_ENV=/{print "app_env=" $2} /^SECRET_KEY=/{v=substr($0, 12); gsub(/["\047]/, "", v); print "secret_key_caracteres=" length(v); print "secret_key_texto_de_exemplo=" (tolower(v) ~ /gere_uma_chave|troque|changeme|change_me/)}\' /var/www/.env';
assert_same([1, $secretLine], [count($secretLines), $commands[$secretLines[0] ?? -1] ?? ''], 'roteiro unico: a SECRET_KEY so aparece na conferencia que mostra o tamanho e se e texto de exemplo, nunca o valor');

// ----------------------------------------------------------------------------
// 10. Pre-requisitos, -Browser na estacao, aviso a gestao
// ----------------------------------------------------------------------------
foreach (['0a. ', '0b. ', '0c. ', '0d. ', '0e. ', '0f. ', '0g. ', '0h. ', '0i. ', '0j. ', '0k. ', '0l. ', '0m. ', '0n. ', '0o. ', '0p. ', '0r. '] as $item) {
    assert_same(true, strpos($runbook, "\n{$item}") !== false, "roteiro unico: pre-requisito {$item}presente");
}
// 0r (9.13.7): o que a auditoria deixou A CONFIRMAR e e so contagem, na
// vespera, antes de gravar o commit.
$eveAuditLines = [$first('npm ping && echo "registro do npm alcancado" || echo "PARE: '), $first('AS admin_rotulo_antigo,'), $first("COLUMN_NAME = 'ad_login_ativo') AS ad_login_ativo"), $first('SUM(note IS NOT NULL AND note <> \'\') AS com_observacao FROM portal_schedule_exceptions GROUP BY exception_type;'), $first('echo "timers com chronodesk: $(systemctl list-timers --all'), $first('echo "PHP_ERROR_LOG no .env: ')];
assert_same([], array_values(array_filter($eveAuditLines, static fn (int $i): bool => $i < 0 || $i >= $recordLine || $i < $evePreflightLine)), 'vespera (0r): npm ping, M9, ad_login_ativo e audit_log.username, A1, cron e timers e o log do PHP, antes do dia');
assert_same(true, strpos($prose, 'itens 0a a 0r feitos na vespera') !== false && strpos($prose, 'Anote-o como `<log de erros do PHP>` (marcador do 6f)') !== false, 'roteiro unico: passo 1 com o 0r e o marcador do log do PHP');
foreach (['Pendencia 16a', 'Pendencia 16b', 'Pendencia 16c', '0a2: `APP_ENV`', '`gitleaks` instalado', 'Logs e `curl` das paginas legadas', 'OBRIGATORIO, na estacao, sobre o commit que vai ser gravado', 'Q-F6, variante', 'Q-F7, variante', 'Referencia do `status.php` e do `dashboard.php`'] as $needle) {
    assert_same(true, strpos($prose, $needle) !== false, "roteiro unico: pre-requisito {$needle}");
}
$browserLine = array_values(array_filter($psCommands, static fn (string $line): bool => strpos($line, 'test-local.ps1 -SkipHttp -Browser') !== false));
assert_same(1, count($browserLine), 'roteiro unico: -Browser na estacao, numa linha');
assert_same(true, strpos($browserLine[0] ?? '', 'git worktree add --detach ..\\chronodesk-deploy1 origin/feat/employee-form-rhf-zod') !== false && substr(rtrim($browserLine[0] ?? ''), -18) === 'git rev-parse HEAD', 'roteiro unico: -Browser sobre o commit do branch do deploy, com o hash no fim');
assert_same(true, strpos($runbook, '0o. **Roteiro de navegador') < strpos($runbook, '**2. Branch'), 'roteiro unico: -Browser antes de gravar o commit');
preg_match_all('/^> .*$/m', $runbook, $noticeLines);
$notice = implode("\n", $noticeLines[0]);
assert_same([true, true, true], [strpos($notice, 'ate [N_PAS] PAs') !== false, strpos($notice, '[N_SEM_REGRA] tecnicos') !== false, strpos($notice, '[DATA_CORTE]') !== false], 'aviso a gestao: marcadores [N_PAS], [N_SEM_REGRA] e [DATA_CORTE]');
// A data, a janela e a indisponibilidade das linhas do inicio (9.13.6) sao os
// unicos numeros fixos do aviso; o resto vem dos marcadores.
$noticeFlat = (string)preg_replace('/\s+/', ' ', str_replace("\n> ", ' ', $notice));
$noticeWhen = $deployDate !== null ? sprintf('%s, das %s:%s as %s:%s', $deployDate->format('d/m'), $d[4], $d[5], $d[6], $d[7]) : '';
$noticeOutage = $outageLine !== '' ? sprintf('cerca de %s minutos, previstos entre %s:%s e %s:%s, com o turno da noite em operacao', $outageLines[1][0], $outageLines[2][0], $outageLines[3][0], $outageLines[4][0], $outageLines[5][0]) : '';
assert_same([true, true], [$noticeWhen !== '' && strpos($noticeFlat, $noticeWhen) !== false, $noticeOutage !== '' && strpos($noticeFlat, $noticeOutage) !== false], 'aviso a gestao: a data, a janela e o horario da indisponibilidade das linhas do inicio');
assert_same(0, preg_match('/\d/', str_replace(['N1', 'N2', $noticeWhen, $noticeOutage], '', $noticeFlat)), 'aviso a gestao: sem outro numero fixo (so os marcadores)');
assert_same(0, preg_match('/contrato|cr[ií]tico|F8|F9|almo[cç]o por/i', $notice), 'aviso a gestao: nada do F8 nem do F9');
foreach (['escala do dia', 'contorno vermelho', 'E um aviso, nao um bloqueio', '"CIs disponiveis"', 'A Lideranca aparece numa linha a parte', 'tela Ausencias', '"Arquivar"', 'telas antigas'] as $needle) {
    assert_same(true, strpos((string)preg_replace('/\s+/', ' ', str_replace("\n> ", ' ', $notice)), $needle) !== false, "aviso a gestao: fala de {$needle}");
}
assert_same([true, true, true], [strpos($prose, '`[N_PAS]` e o `pas_compartilhados_hoje` da Q-F6') !== false, strpos($prose, '`[N_SEM_REGRA]` e o `n1_n2_sem_regra_hoje` da Q-F7') !== false, strpos($prose, '`[DATA_CORTE]` e o `corte planejado` do passo 0i') !== false], 'aviso a gestao: a consulta de cada marcador');
// A1 (9.13.7): o GET da escala ainda entrega a observacao da excecao a
// qualquer perfil; o aviso nao promete o contrario.
assert_same(true, strpos($noticeFlat, 'Na Escala presencial, a observacao das excecoes de escala continua visivel a todos os perfis') !== false, 'aviso a gestao: nao promete o fim da observacao da excecao de escala visivel a todos (A1)');
foreach (['inclusive o registro de pausas', 'entrar de novo', 'tres horas mais cedo', 'CSV de horas extras'] as $needle) {
    assert_same(true, strpos((string)preg_replace('/\s+/', ' ', str_replace("\n> ", ' ', $notice)), $needle) !== false, "aviso a gestao: fala de {$needle} (fila antiga)");
}

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
// APP_BASE_URL monta os links dos e-mails: com https:// e o portal so em HTTP,
// os links apontariam para um endereco que nao atende.
preg_match_all('/^APP_BASE_URL=(.*)$/m', $prodExample, $baseUrls);
assert_same([1, 1], [count($baseUrls[1]), preg_match('#^http://[^\s/]+#', trim($baseUrls[1][0] ?? ''))], '.env.production.example: APP_BASE_URL com http://, uma vez');

// ----------------------------------------------------------------------------
// 12b. test-linux.sh e test-local.ps1 rodam todos os QAs PHP do repositorio
// ----------------------------------------------------------------------------
$repoQaNames = array_map(static fn (string $path): string => basename($path, '.php'), glob($root . '/scripts/qa-*.php') ?: []);
sort($repoQaNames);
preg_match_all('/^php scripts\/(qa-[a-z0-9-]+)\.php\b/m', $readOptional($root . '/scripts/test-linux.sh'), $linuxQas);
preg_match_all('/^\s*& \$phpExecutable \(Join-Path \$repoRoot "scripts\\\\(qa-[a-z0-9-]+)\.php"\)/m',$readOptional($root . '/scripts/test-local.ps1'), $localQas);
$linuxQaNames = array_values(array_unique($linuxQas[1]));
$localQaNames = array_values(array_unique($localQas[1]));
sort($linuxQaNames);
sort($localQaNames);
assert_same([$repoQaNames, $repoQaNames], [$linuxQaNames, $localQaNames], 'test-linux.sh e test-local.ps1: rodam todos os QAs PHP do repositorio (QA novo entra nos dois)');
// [pos-deploy 1, 9.14.4] O test-local.ps1 e a bateria completa: roda tambem o
// frontend (lint, typecheck, build e todos os qa:* lidos do package.json, nao
// de uma lista fixa), reprova se qualquer um falhar e diz quantos rodaram. A
// secao fica fora do -SkipHttp (no nivel de cima do script).
$testLocal = str_replace("\r\n", "\n", $readOptional($root . '/scripts/test-local.ps1'));
$frontendPackage = json_decode($readOptional($root . '/frontend/package.json'), true);
$frontendQaCount = count(array_filter(array_keys($frontendPackage['scripts'] ?? []), static fn (string $name): bool => strpos($name, 'qa:') === 0));
assert_same([true, true, true, true, true, true], [
    strpos($testLocal, "\nWrite-Section \"Frontend (npm: lint, typecheck, build e qa:*)\"\n") !== false,
    strpos($testLocal, '$packageJson = Get-Content -Raw -Encoding UTF8 (Join-Path $frontendRoot "package.json") | ConvertFrom-Json') !== false,
    strpos($testLocal, '$qaScripts = @($scriptNames | Where-Object { $_ -like "qa:*" })') !== false && strpos($testLocal, '$baseScripts = @("lint", "typecheck", "build")') !== false,
    strpos($testLocal, '$output = & $npm.Source run -s $name 2>&1') !== false && strpos($testLocal, 'Fail "npm run $name falhou"') !== false,
    strpos($testLocal, 'Write-Host "frontend: $($npmScripts.Count) scripts npm rodados (lint, typecheck, build e $($qaScripts.Count) qa:*): $npmPassed OK, $($npmFailed.Count) com falha"') !== false,
    strpos($testLocal, 'Fail "Node.js ou npm nao encontrado; o frontend faz parte da bateria completa"') !== false && strpos($testLocal, 'Fail "frontend\\node_modules ausente;') !== false,
], 'test-local.ps1: frontend na bateria (lint, typecheck, build e os qa:* do package.json), falha em qualquer um, resumo com a contagem');
assert_same(true, $frontendQaCount >= 19 && strpos($testLocal, 'Write-Section "Frontend') < strpos($testLocal, 'Write-Section "Navegador'), 'test-local.ps1: frontend antes do navegador, com os qa:* do package.json (19 hoje, 22 scripts no total)');

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
assert_same([], array_values(array_filter($sheetAll, static fn (string $line): bool => preg_match('/\bsudo\b/', $line) === 1)), 'folha do dia: nenhum comando com sudo (shell root do sudo -i)');
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
assert_same([1, 1, 1, 1], [substr_count($sheet, "\n" . $dateLine . "\n"), substr_count($sheet, "\n" . $eveLine . "\n"), substr_count($sheet, "\n" . $outageLine . "\n"), substr_count($sheet, "\n" . $companionLine . "\n")], 'folha do dia: data e janela, vespera, indisponibilidade e quem acompanha iguais as do roteiro');
// O esperado do 0i (data do corte): com a data preenchida, o corte da linha
// da data; com A DEFINIR, o formato e a regra da D2.
$cutoffExpected = '`corte planejado: ' . $plannedCutoff . '`';
assert_same(1, preg_match('/^- \[ \] \*\*\d+\.\*\* \(0i\) .*' . preg_quote($cutoffExpected, '/') . '/m', $sheet), 'folha do dia: o esperado do 0i e o corte da linha da data');
assert_same(true, $windowText !== '' && strpos($sheet, '(**' . $windowText . '**)') !== false && strpos($sheet, 'A DEFINIR') === false, 'folha do dia: o passo 1 com a data e a janela, sem A DEFINIR');
$sheetWithoutLoopback = str_replace('127.0.0.1', '', $sheet);
assert_same([0, 0, 0, 0], [
    preg_match('/\b\d{1,3}(\.\d{1,3}){3}\b/', $sheetWithoutLoopback),
    preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\./', $sheet),
    preg_match('/\b[a-z0-9-]+(\.[a-z0-9-]+)*\.(local|lan|corp|intra|internal|com|br|net|org)\b/i', $sheet),
    preg_match('/MYSQL_PWD=(?!"\$P")|\s-p(?!rint|ath)\S/', $sheet),
], 'folha do dia: sem IP (alem do 127.0.0.1), e-mail, hostname ou senha');

echo "QA deploy runbook OK ({$checks} verificacoes)\n";
