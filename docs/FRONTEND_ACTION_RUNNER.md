# Portal SDK - Frontend Action Runner

## Motivo

A Fase 18 reduz duplicidade no padrao transacional do frontend:

```text
action -> refresh -> reset -> notify
```

O objetivo e manter a UX da Fase 17, mas centralizar tratamento de erro, refresh, feedback de sucesso e callback de reset em um helper pequeno.

## Helper criado

Arquivo:

- `frontend/src/lib/actionRunner.ts`

Assinatura:

```ts
export type ActionRunnerOptions<T> = {
  action: () => Promise<T>
  refresh?: (() => Promise<unknown> | unknown) | Array<() => Promise<unknown> | unknown>
  notify: (message: string, type?: string) => void
  successMessage?: string | null
  onSuccess?: (result: T) => Promise<unknown> | unknown
  onError?: (error: unknown) => Promise<unknown> | unknown
}

export async function runAction<T>(options: ActionRunnerOptions<T>): Promise<T | null>
```

## Ordem de execucao

1. Executa `action`.
2. Executa um ou mais `refresh`.
3. Executa `onSuccess`, quando informado.
4. Exibe `successMessage`, exceto quando `successMessage === null`.
5. Em erro, executa `onError`, quando informado, e chama `notify(message, 'error')`.

Essa ordem evita mostrar sucesso antes de a lista atualizar e preserva os resets da Fase 17.

## Fluxos migrados

- `AdminPage.jsx`
  - aprovar/rejeitar solicitacoes;
  - salvar configuracoes;
  - criar, atualizar e remover funcionario;
  - criar, atualizar perfil e remover usuario local;
  - alterar senha administrativa.

- `OperationalPages.jsx`
  - criar evento de calendario;
  - salvar regra de escala;
  - remover regra de escala;
  - salvar excecao de escala;
  - confirmar importacao de escala;
  - criar solicitacao de horas extras/correcao;
  - aprovar/rejeitar workflow;
  - cadastrar plantao.

- `CriticalIncidentsPage.jsx`
  - criar chamado critico;
  - editar chamado critico;
  - alterar status do chamado critico.

## Fluxos fora do escopo

Mantidos no padrao local da Fase 17:

- PA Map;
- importacao de chamados criticos;
- documentos;
- escala de sabado;
- pausas em tempo real.

Esses fluxos envolvem warnings condicionais, upload, preview, detalhes ou interacao em tempo real. A migracao pode ser considerada em fases futuras, uma tela por vez.

## Contratos preservados

- Nenhum endpoint novo.
- Nenhum payload alterado.
- Nenhuma querystring alterada.
- Nenhuma mudanca em CSRF, RBAC, autenticacao ou auditoria.
- Nenhuma mutation TanStack Query.
- Nenhum `fetch` fora de `api.ts`.
- Nenhuma dependencia nova.

## QA

Criado:

- `frontend/scripts/qa-action-runner.mjs`
- script `npm run qa:action-runner`

O QA valida:

- existencia e assinatura do runner;
- uso em Admin e Operacional;
- uso nos fluxos simples de Chamados Criticos;
- importacao de Chamados Criticos preservada fora do runner;
- ausencia de `fetch`, `post`, `postForm`, `innerHTML`, `localStorage` e `dangerouslySetInnerHTML` no runner;
- resets e mensagens da Fase 17 preservados;
- `fixed_weekdays` continua limpando `weekdays`;
- `Feedback`, `RouteErrorBoundary` e credito do desenvolvedor continuam presentes;
- arquivos protegidos nao foram alterados.

## Rollback

- Remover `frontend/src/lib/actionRunner.ts`.
- Restaurar `perform` em `AdminPage.jsx` e `submit` em `OperationalPages.jsx` para o try/catch local anterior.
- Remover `frontend/scripts/qa-action-runner.mjs`.
- Remover `qa:action-runner` de `frontend/package.json`.
- Reverter ajustes em `frontend/scripts/qa-visual.mjs` e docs.

## Riscos residuais

- O runner ainda nao cobre uploads, importacao de chamados criticos, PA Map ou pausas.
- Validacao manual por perfil continua necessaria.
- O helper centraliza ordem de execucao; alteracoes futuras nele devem passar por QA completo porque afetam multiplos fluxos.

## Proximos passos

- Validar manualmente Admin e Escalas com dados de teste.
- Avaliar migracao de uma unica tela adicional por fase.
- Evitar migrar uploads ou PA Map sem teste manual dedicado.
