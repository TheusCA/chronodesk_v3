# Portal SDK - Operational Lib Migration

## Status

Fase 8 migrou `frontend/src/lib/operational.js` para `frontend/src/lib/operational.ts`.

## Escopo executado

- Preservados os exports publicos existentes:
  - `IMPORT_LIMITS`
  - `CRITICAL_INCIDENT_IMPORT_LIMITS`
  - `competencyFor`
  - `localDate`
  - `queryString`
  - `parseCsv`
  - `parseCriticalIncidentCsv`
  - `minutesLabel`
- Adicionados tipos permissivos no proprio modulo:
  - `ImportLimits`
  - `CsvRow`
  - `HeaderAliasMap`
  - `QueryValue`
  - `QueryParams`
  - `CompetencyPeriod`
  - `ScheduleRuleType`
- Nenhuma tela, hook, formulario, tabela ou componente foi migrado.
- Nenhum `.tsx` de runtime foi criado.
- Nenhuma dependencia nova foi instalada.

## Invariantes protegidas

- Limite `IMPORT_LIMITS.maxFileBytes` preservado em `2097152`.
- Extensoes de importacao operacional preservadas em `.csv` e `.xlsx`.
- Limite `CRITICAL_INCIDENT_IMPORT_LIMITS.maxFileBytes` preservado em `2097152`.
- Extensoes de importacao de chamados criticos preservadas em `.csv` e `.xlsx`.
- `queryString` continua omitindo `''`, `null` e `undefined`.
- Competencia 16-15 continua validada por `qa:operational`.
- Parser CSV e parser War Room continuam validados por `qa:operational`.
- Valores canonicos de escala continuam protegidos:
  - `even_days`
  - `odd_days`
  - `always_onsite`
  - `always_remote`
  - `undefined`

## QA atualizado

- `frontend/scripts/qa-operational.mjs` agora carrega `operational.ts` via transpile em memoria usando a `devDependency` TypeScript ja existente.
- `frontend/scripts/qa-operational.mjs` valida que `operational.js` nao existe mais.
- `frontend/scripts/qa-operational.mjs` valida que nenhuma pagina foi migrada para TypeScript e que nenhum `.tsx` de runtime foi criado.
- `frontend/scripts/qa-operational.mjs` valida que a POC continua fora do runtime e que dependencias proibidas continuam ausentes.
- `frontend/scripts/qa-visual.mjs` foi atualizado para exigir `src/lib/operational.ts`.

## Fixtures

Nenhuma fixture nova foi criada nesta fase. Os CSVs usados no QA permanecem inline, pequenos e ficticios, com chamados `INC001` e `INC002`.

## Riscos residuais

- O TypeScript segue permissivo (`strict=false`), entao ainda nao substitui smoke funcional.
- Paginacao, filtros e uploads continuam dependentes de testes integrados por tela.
- Limites e extensoes finais de upload continuam autoridade do backend.

## Proximos candidatos

- Tipar `src/lib/api.js` sem alterar endpoints, CSRF, credenciais ou contratos.
- Migrar um componente puro de UI de baixo risco.
- Criar tipos compartilhados para respostas de API antes de migrar paginas.
