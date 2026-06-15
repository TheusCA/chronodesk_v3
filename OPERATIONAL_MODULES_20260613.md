# ChronoDesk - entrega operacional 2026-06-13

## Escopo

- Calendario consolidado com eventos manuais, pausas, escala, horas extras,
  ajustes de ponto e plantoes.
- Regras de escala presencial por dias pares, dias impares, sempre remoto,
  sempre presencial ou sem definicao.
- Excecoes de escala por data e importacao CSV com previa.
- Horas extras e ajustes de ponto com criacao, RBAC, aprovacao e rejeicao.
- Plantoes com periodo, horario, tipo e equipe.
- Relatorio por competencia operacional do dia 16 ao dia 15, com CSV.
- Fila MySQL e contrato isolado para futura sincronizacao SharePoint/Graph.
- Portal React como entrada oficial para raiz e paginas visuais legadas.

## Banco

Aplicar, nesta ordem, sem remover migrations anteriores:

1. `migrations/20260612_001_portal_foundation.sql`
2. `migrations/20260612_002_notification_reads.sql`
3. `migrations/20260613_003_operational_modules.sql`
4. `migrations/20260613_004_operational_hardening.sql`
5. `migrations/20260613_005_documents_and_employee_roles.sql`

A migration nova cria tabelas independentes e nao apaga nem altera dados
legados. O usuario runtime continua sem privilegio de DDL.

## Compatibilidade do legado

O `.htaccess` redireciona somente requisicoes `GET` e `HEAD`:

- `/` e `/index.php` para `/app/`
- `/login.php` para `/app/`
- `/admin_login.php` e `/admin.php` para `/app/admin`
- `/metricas.php` para `/app/metricas`

POSTs para `login.php` e `admin_login.php` continuam executando o fluxo legado
protegido. Assim, o fallback de emergencia nao foi removido. APIs, services,
classes, migrations e assets nao sao redirecionados.

## APIs

- `GET|POST /api/portal/calendar.php`
- `GET|POST /api/portal/schedules.php`
- `GET|POST /api/portal/overtime.php`
- `GET|POST /api/portal/time_corrections.php`
- `GET|POST /api/portal/oncall.php`
- `GET /api/portal/reports.php`
- `GET /api/portal/reports.php?format=csv`

Todas as escritas exigem sessao, CSRF e JSON. Regras, excecoes, eventos e
plantoes exigem gestor/admin. Tecnicos so podem criar horas extras e ajustes
para o proprio `funcionario_id`. O backend impede autoaprovacao.

## SharePoint

`portal_sync_queue` recebe payloads de escala, hora extra, ajuste e plantao.
`SharePointSyncService.php` concentra o contrato futuro. Client secret e token
nao fazem parte do schema nem do codigo; deverao vir do ambiente quando o
Microsoft Graph for ativado. O MySQL permanece a fonte principal.

## Limites conhecidos

- Importacoes de War Room e escala presencial aceitam CSV e XLSX com
  validacao estrutural no backend. A VM precisa das extensoes PHP `fileinfo`
  e `zip`.
- XLS legado (BIFF/OLE) nao e interpretado sem uma biblioteca especializada.
  Converta o arquivo para XLSX ou CSV; a interface informa essa limitacao.
- O worker Microsoft Graph ainda nao envia dados; a fila fica persistida para
  processamento e reprocessamento em uma fase posterior.
- Documentos PDF, DOC, DOCX, XLSX, CSV, TXT, MD, PNG e JPEG ficam fora do
  webroot, com allowlist, validacao de MIME/conteudo e download autenticado. A
  varredura por antivirus permanece pendente e deve ser adicionada antes de
  aceitar arquivos de origens nao confiaveis.
- Testes autenticados e de persistencia dependem de MySQL e AD do ambiente.

## Validacao

```powershell
cd frontend
npm run lint
npm run build

cd ..
.\scripts\test-local.ps1 -SkipHttp -PhpPath "C:\caminho\php.exe"
.\scripts\test-local.ps1 -BaseUrl "http://127.0.0.1" -PhpPath "C:\caminho\php.exe"
```

Checklist manual:

- tecnico cria apenas os proprios lancamentos;
- gestor diferente do criador aprova ou rejeita;
- criador nao aprova o proprio lancamento;
- competencia Abril/2026 retorna 16/03/2026 a 15/04/2026;
- regra par gera presencial no dia 16 e remoto no dia 17;
- excecao substitui a regra fixa na data;
- CSV invalido nao pode ser confirmado;
- exportacao CSV neutraliza formulas iniciadas por `=`, `+`, `-` ou `@`;
- refresh direto funciona nas seis rotas novas;
- `.env`, `.git`, `frontend`, `services` e `migrations` retornam 403/404.
