# Portal SDK - Schedule Rule Form RHF/Zod

## Motivo

A Fase 20 reduziu estado manual e validacoes espalhadas no formulario de regra da escala presencial/home office. A mudanca melhora a validacao client-side sem substituir as regras do backend.

## Escopo

Campos cobertos:

- `employee_id`;
- `rule_type`;
- `effective_from`;
- `weekdays`, somente quando `rule_type = fixed_weekdays`.

Fora do escopo:

- excecoes de escala;
- importacao de escala;
- relatorios;
- PA Map;
- chamados criticos;
- documentos;
- escala de sabado;
- pausas em tempo real;
- formularios de funcionario;
- backend, banco, endpoints, payloads, autenticacao, CSRF, RBAC e auditoria.

## Schema

`frontend/src/lib/formSchemas.ts` agora exporta `scheduleRuleSchema`.

Validacoes:

- `employee_id` e obrigatorio e precisa representar um numero positivo;
- `rule_type` aceita apenas `undefined`, `even_days`, `odd_days`, `always_onsite`, `always_remote` e `fixed_weekdays`;
- `effective_from` e obrigatorio no formato `YYYY-MM-DD`;
- `weekdays` aceita apenas `mon`, `tue`, `wed`, `thu` e `fri`;
- `fixed_weekdays` exige ao menos um dia selecionado.

## Comportamento

O formulario usa React Hook Form em `OperationalPages.jsx` com validacao manual por `scheduleRuleSchema.safeParse`. Nao foi usado `@hookform/resolvers`.

Quando a regra deixa de ser `fixed_weekdays`, o frontend limpa `weekdays` com `setValue`. Quando a regra volta para `fixed_weekdays`, os checkboxes aparecem novamente e o schema exige ao menos um dia.

Erros client-side aparecem abaixo dos campos, sem `alert`, modal ou stack trace.

## Payload

Payload preservado para regra comum:

```json
{
  "action": "rule",
  "employee_id": 123,
  "rule_type": "even_days",
  "effective_from": "2026-06-26"
}
```

Payload preservado para `fixed_weekdays`:

```json
{
  "action": "rule",
  "employee_id": 123,
  "rule_type": "fixed_weekdays",
  "effective_from": "2026-06-26",
  "weekdays": ["mon", "wed", "fri"]
}
```

`employee_id` continua sendo convertido com `Number(...)` apenas na montagem do payload. `weekdays` so e enviado para `fixed_weekdays`.

## Reset Pos-Sucesso

O fluxo continua usando `submit(...)`, que delega ao `runAction`.

Apos sucesso:

- a lista e atualizada por `resource.refresh`;
- o formulario executa `resetRule(emptyScheduleRule(parsed.data.effective_from || today))`;
- colaborador volta a vazio;
- regra volta para `undefined`;
- `weekdays` e limpo;
- a data de vigencia util e preservada;
- o toast `Escala salva com sucesso.` continua vindo de `ACTION_FEEDBACK.scheduleSaved`.

Na remocao, `ACTION_FEEDBACK.scheduleRuleRemoved` foi preservado e o formulario e resetado quando a regra removida corresponde ao colaborador em edicao.

## QA

Criado:

- `frontend/scripts/qa-schedule-form.mjs`;
- script `qa:schedule-form`.

O QA valida schema, RHF/Zod, payload, ausencia de resolver externo, ausencia de dependencias proibidas, AppSec, arquivos protegidos, credito do desenvolvedor, `RouteErrorBoundary`, ausencia de mutation e ausencia de `.tsx` runtime.

## AppSec

A fase nao adiciona:

- `fetch` direto fora de `api.ts`;
- `innerHTML`;
- `dangerouslySetInnerHTML`;
- `localStorage`;
- `alert`;
- novo `confirm`;
- endpoint novo;
- log de segredo;
- dependencia nova.

CSRF, RBAC, autenticacao e auditoria permanecem no contrato existente do backend e do transporte `api.ts`.

## Riscos Residuais

- O backend continua sendo a autoridade final para permissao, conflitos e persistencia.
- O QA local e estatico; ainda e necessario smoke manual autenticado por perfil.
- A tela `OperationalPages.jsx` segue densa e deve ser quebrada apenas em fases futuras planejadas.

## Rollback

- Remover `scheduleRuleSchema`.
- Restaurar o formulario de regra para `useState` local.
- Remover `qa:schedule-form`.
- Reverter ajustes nos QAs de feedback/runner/visual.
- Reverter esta documentacao.

O rollback e de frontend e nao exige migration, porque o contrato de API e banco nao foi alterado.

## Proximos Passos

Migrar outro formulario pequeno em fase isolada, preferencialmente calendario ou plantonistas, mantendo schema pequeno, QA dedicado e comparacao explicita de payload.
