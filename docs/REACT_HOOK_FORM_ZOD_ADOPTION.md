# React Hook Form + Zod Adoption

## Fase 14

Adocao minima e reversivel de React Hook Form + Zod no Portal SDK.

## Dependencias adicionadas

- `react-hook-form`
- `zod`

Nao foram adicionados `@hookform/resolvers`, Formik, Yup, Joi, Playwright ou Cypress.

## Formulario escolhido

O primeiro uso fica restrito aos filtros da tela `/relatorios`, dentro de `OperationalReportsPage`.

Motivos:

- fluxo somente GET;
- ja usa TanStack Query;
- ja usa TanStack Table no resumo;
- export CSV usa URL com querystring;
- nao envolve POST sensivel;
- nao altera backend, banco, pausa, escala, PA Map, chamados criticos ou Admin.

## Campos validados

- `competency`: obrigatorio, formato `YYYY-MM`;
- `team`: somente `''`, `n1` ou `n2`;
- `employee_id`: string opcional, default `''`.

Schema criado em `frontend/src/lib/formSchemas.ts`.

## Contrato preservado

O estado aplicado continua com os mesmos nomes:

```txt
competency
team
employee_id
```

A querystring de consulta continua baseada em:

```txt
queryString(filters)
```

O export CSV continua usando:

```txt
queryString({ ...filters, format: 'csv' })
```

O transporte continua sendo `api()` para GET e `apiUrl()` para o link de exportacao.

## Fora do escopo

- formularios com POST;
- mutations;
- uploads;
- aprovacoes;
- Pausas;
- PA Map;
- Admin;
- Dashboard;
- Critical Incidents;
- Escalas e regra `fixed_weekdays`;
- migracao de paginas para TypeScript;
- criacao de `.tsx` de runtime.

## Rollback

1. Remover `react-hook-form` e `zod` de `frontend/package.json` e `frontend/package-lock.json`.
2. Remover `frontend/src/lib/formSchemas.ts`.
3. Restaurar os filtros de `OperationalReportsPage` para `useState` direto.
4. Remover `frontend/scripts/qa-forms.mjs` e o script `qa:forms`.
5. Reverter ajustes dos QAs que passaram a aceitar RHF/Zod.

## Riscos residuais

- O filtro agora aplica valores no submit do formulario, preservando a querystring final, mas exigindo clique em "Aplicar filtros".
- Validacao frontend melhora ergonomia, mas o backend continua sendo a autoridade.
- Teste manual deve confirmar export CSV com os filtros aplicados.

## Proximos passos

- Manter RHF/Zod restritos a um fluxo por fase.
- Priorizar filtros GET e formularios nao sensiveis antes de qualquer POST.
- Definir padrao aprovado antes de migrar formularios de upload, aprovacao ou Admin.
