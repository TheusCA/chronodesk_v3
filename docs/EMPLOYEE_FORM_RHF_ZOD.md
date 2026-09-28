# Portal SDK - Employee Form RHF/Zod

## Motivo

A Fase 21 reduziu estado manual e validacoes dispersas nos formularios de adicionar e editar funcionarios em Admin. A mudanca melhora a validacao client-side sem substituir permissoes, auditoria ou validacoes finais do backend.

## Escopo

Campos cobertos:

- `id`;
- `nome`;
- `equipe`;
- `jornada_entrada`;
- `jornada_saida`;
- `almoco_inicio`;
- `almoco_fim`;
- `ativo`.

Campos preservados por contrato atual de payload:

- `ad_login`;
- `access_role`.

Fora do escopo:

- usuarios locais;
- alteracao de senha;
- configuracoes do sistema;
- aprovacoes;
- escalas;
- PA Map;
- chamados criticos;
- documentos;
- pausas;
- backend, banco, endpoints, payloads, querystrings, autenticacao, CSRF, RBAC e auditoria.

## Schema

`frontend/src/lib/formSchemas.ts` agora exporta `employeeFormSchema`.

Validacoes:

- `id` obrigatorio, inteiro, entre 1 e 999;
- `nome` obrigatorio, trimado e com minimo de 3 caracteres;
- `equipe` limitada a `n1`, `n2` e `lideranca`;
- horarios obrigatorios em `HH:MM`;
- `jornada_entrada < jornada_saida`;
- `almoco_inicio < almoco_fim`;
- `ativo` booleano com default `true`;
- `ad_login` opcional com caracteres permitidos; `null` e `undefined` viram `''` antes da validacao, porque `listar_funcionarios.php` devolve `ad_login: null` para quem nao tem login AD vinculado;
- `access_role` limitado aos perfis existentes.

Mensagens de validacao em portugues com acentuacao, inclusive para erros de tipo (ex.: `null` em `nome` ou em horario), que sem `error` explicito cairiam na mensagem padrao do Zod em ingles.

## Comportamento

`AdminPage.jsx` usa duas instancias de React Hook Form:

- cadastro de funcionario;
- edicao de funcionario.

A validacao e manual por `employeeFormSchema.safeParse`. Nao foi usado `@hookform/resolvers`.

Erros client-side aparecem abaixo dos campos, sem `alert`, modal ou stack trace. O ID no modo edicao fica `readOnly`, nao `disabled`, para continuar presente no submit.

`equipe`, `access_role` e `ativo` nao exibem mensagem abaixo do campo (sao `select`/checkbox controlados). Quando a validacao falha num desses campos, ou num issue sem campo, a primeira mensagem vai para `notify(..., 'error')`, para o submit nunca falhar em silencio. O foco vai para o primeiro campo com mensagem visivel, exceto o ID no modo edicao.

Ao abrir a edicao, `edit()` normaliza `ad_login: item.ad_login ?? ''`. No payload, `''` equivale ao `null` anterior: o backend normaliza login vazio para `null` em `normalizar_samaccountname`.

## Payload

Cadastro preservado:

```json
{
  "id": 123,
  "nome": "Funcionario Teste",
  "ad_login": "funcionario.teste",
  "equipe": "n1",
  "access_role": "tecnico",
  "jornada_entrada": "08:00",
  "jornada_saida": "17:00",
  "almoco_inicio": "12:00",
  "almoco_fim": "13:00",
  "ativo": true
}
```

Edicao preservada:

```json
{
  "funcionario_id": 123,
  "id": 123,
  "nome": "Funcionario Teste",
  "ad_login": "funcionario.teste",
  "equipe": "n1",
  "access_role": "tecnico",
  "jornada_entrada": "08:00",
  "jornada_saida": "17:00",
  "almoco_inicio": "12:00",
  "almoco_fim": "13:00",
  "ativo": true
}
```

`id` e `funcionario_id` continuam sendo enviados como numero.

## Reset Pos-Sucesso

O fluxo continua usando `perform(...)`, que delega ao `runAction`.

Apos criar:

- a lista e atualizada por `resource.refresh`;
- o formulario executa `resetCreateEmployee(emptyEmployee)`;
- o toast `Funcionario adicionado com sucesso.` continua vindo de `ACTION_FEEDBACK.employeeCreated`.

Apos editar:

- a lista e atualizada por `resource.refresh`;
- o modo edicao fecha com `setEditForm(null)`;
- o toast `Funcionario atualizado com sucesso.` continua vindo de `ACTION_FEEDBACK.employeeUpdated`.

Na remocao, `ACTION_FEEDBACK.employeeRemoved` foi preservado e a edicao fecha quando o funcionario removido e o mesmo que estava aberto.

## QA

Criado:

- `frontend/scripts/qa-employee-form.mjs`;
- script `qa:employee-form`.

O QA valida RHF/Zod no Admin, payloads, ausencia de resolver externo, ausencia de dependencias proibidas, AppSec, credito do desenvolvedor, `RouteErrorBoundary`, ausencia de mutation e ausencia de `.tsx` runtime.

O schema e testado por comportamento: o script importa `formSchemas.ts` direto (type stripping nativo do Node 24) e roda `safeParse` com casos validos e invalidos:

- `ad_login` `null`, `undefined` e `''` validam e viram `''`;
- horarios fora de `HH:MM` (`24:00`, `8:00`, `08:60`, texto, vazio, `null`) falham no proprio campo;
- entrada depois ou igual a saida falha em `jornada_saida`; almoco invertido falha em `almoco_fim`;
- `equipe` e `access_role` fora da lista falham com mensagem propria;
- `id` vazio, zero, acima de 999 ou fracionario, `nome` curto e `ativo` nao booleano falham;
- nenhuma mensagem cai no texto padrao do Zod em ingles.

Checagens baseadas em `git diff` da arvore de trabalho foram retiradas deste script, porque so enxergam mudancas nao commitadas e passam vazias depois do commit:

- "nao adicionar novo confirm/alert" virou inventario fechado de `window.confirm` por arquivo, que vale com a arvore limpa; `alert` ja era checado no fonte inteiro;
- "arquivos protegidos nao alterados" foi removida. E restricao de escopo do diff, nao invariante do repositorio: um inventario fixo quebraria na primeira fase futura que alterar `api.ts` legitimamente. Na revisao, verificar com `git diff --name-only <base>..HEAD -- frontend/src/hooks frontend/src/lib`; no fechamento do A5, com base `0db826d`, apenas `frontend/src/lib/formSchemas.ts` aparece.

Os QAs de fases anteriores (`qa-action-feedback`, `qa-action-runner`, `qa-bundle`, `qa-forms`, `qa-schedule-form`, `qa-ux-hardening`) ainda usam o mesmo padrao de `git diff`; ficam como estao para nao alterar entregas ja aprovadas.

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

- O backend continua sendo a autoridade final para permissoes, unicidade real e persistencia.
- O QA local e estatico, com teste de comportamento apenas do schema; ainda e necessario smoke manual autenticado por perfil, incluindo editar um funcionario sem login AD.
- `AdminPage.jsx` segue denso e deve ser quebrado apenas em fases futuras planejadas.

## Rollback

- Remover `employeeFormSchema`.
- Restaurar os formularios de funcionario para `useState` local.
- Remover `qa:employee-form`.
- Reverter ajustes nos QAs de feedback/runner/forms/visual.
- Reverter esta documentacao.

O rollback e de frontend e nao exige migration, porque o contrato de API e banco nao foi alterado.

## Proximos Passos

Migrar outro formulario pequeno em fase isolada, mantendo schema pequeno, QA dedicado e comparacao explicita de payload.
