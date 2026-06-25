# Portal SDK - Form Action Feedback UX

## Motivo

A Fase 17 melhora a experiencia depois de acoes transacionais no frontend. O objetivo e deixar claro quando uma acao terminou com sucesso, limpar campos que ja foram processados e recarregar as listas relacionadas sem alterar regras de negocio.

## Padrao de feedback

- Componente evoluido: `frontend/src/components/Feedback.jsx`.
- Tipos suportados: `success`, `error`, `warning` e `info`.
- Acessibilidade: sucesso/aviso/informacao usam `role="status"`; erro usa `role="alert"`.
- Comportamento: fecha automaticamente pelo timer existente do `App.jsx` e tambem pode ser fechado manualmente.
- Sem dependencia externa de toast/modal.
- Sem `localStorage`, `innerHTML` ou `dangerouslySetInnerHTML`.

## Helper de mensagens

Criado `frontend/src/lib/actionFeedback.ts` para centralizar mensagens de sucesso:

- `Funcionário adicionado com sucesso.`
- `Funcionário atualizado com sucesso.`
- `Funcionário removido com sucesso.`
- `Escala salva com sucesso.`
- `Regra de escala removida com sucesso.`
- `Exceção salva com sucesso.`
- `Vínculo realizado com sucesso.`
- `Vínculo removido com sucesso.`
- `Solicitação aprovada com sucesso.`
- `Solicitação rejeitada com sucesso.`
- `Chamado crítico registrado com sucesso.`
- `Chamado crítico atualizado com sucesso.`
- `Documento adicionado com sucesso.`
- `Documento removido com sucesso.`
- `Escala de sábado publicada com sucesso.`

O helper tambem possui `decisionFeedback()` para aprovacao/rejeicao e `importFeedback()` para resumo de importacao quando houver contagem.

## Formularios ajustados

- Admin / Funcionarios:
  - criar limpa o formulario de novo funcionario;
  - editar fecha o modo de edicao;
  - remover fecha a edicao se o funcionario removido estiver aberto;
  - lista e recarregada via `resource.refresh()`.

- Admin / Usuarios e Seguranca:
  - criar usuario limpa login/senha/perfil;
  - alterar perfil/remover usuario exibem mensagem especifica;
  - alterar senha limpa senha e confirmacao.

- Escalas / Operacional:
  - salvar regra limpa colaborador, volta `rule_type` para `undefined` e limpa `weekdays`;
  - remover regra limpa o formulario quando a regra removida estava em edicao;
  - salvar excecao limpa colaborador e observacao, preservando a data de trabalho;
  - importacao limpa preview, linhas e input de arquivo.

- PA Map:
  - salvar vinculo limpa formulario/modal local via reset existente;
  - aviso de alocacao remota usa tipo `warning`;
  - remover vinculo recarrega mapa/lista e mostra mensagem especifica.

- Chamados criticos:
  - criar/editar fecha o formulario e recarrega lista;
  - mudanca de status mostra mensagem especifica;
  - importacao limpa preview, linhas e input de arquivo.

- Documentos e Escala de Sabado:
  - uploads limpam arquivo, metadados transacionais e input nativo;
  - listas sao recarregadas apos sucesso;
  - filtros de consulta sao preservados.

- Pausas:
  - o formulario de nova pausa ja limpava motivo e observacao apos sucesso e foi mantido.

## Campos preservados

- Filtros de relatorios e listas.
- Competencias e datas de trabalho quando ajudam o fluxo operacional.
- Estado de sessao, rota, sidebar e permissoes.
- Contratos de payload e querystring.

## Fora do escopo

- Nenhum backend foi alterado.
- Nenhum endpoint, payload, querystring, CSRF, RBAC, autenticacao ou auditoria foi alterado.
- Nenhuma regra de pausa, escala, PA Map ou chamados criticos foi alterada.
- Nenhuma mutation TanStack Query foi criada.
- Nenhuma dependencia nova foi instalada.

## QA

Criado `frontend/scripts/qa-action-feedback.mjs` e script `npm run qa:action-feedback`.

O QA valida:

- tipos do toast e roles;
- ausencia de `localStorage`, `innerHTML` e `dangerouslySetInnerHTML`;
- mensagens padronizadas;
- resets principais;
- refresh apos sucesso;
- ausencia de `alert()` e de novo `confirm()`;
- preservacao de `api.ts`, `useResource.ts`, `useLivePauses.js`, `queryClient.ts`, `queryKeys.ts` e `operational.ts`;
- ausencia de dependencia nova;
- credito do desenvolvedor e `RouteErrorBoundary` preservados.

## Rollback

- Remover `frontend/src/lib/actionFeedback.ts`.
- Restaurar `Feedback.jsx` para a versao simples.
- Reverter ajustes locais nas paginas alteradas.
- Remover `frontend/scripts/qa-action-feedback.mjs`.
- Remover o script `qa:action-feedback` de `frontend/package.json`.

## Riscos residuais

- Validacao manual ainda e necessaria para fluxos que dependem de permissao e dados reais de operacao.
- Importacoes podem retornar mensagens/contagens diferentes por backend; erros continuam sendo exibidos pelo tratamento existente.
- Alguns `window.confirm` legados permanecem por seguranca operacional, mas a Fase 17 nao adicionou novos usos.

## Proximos passos

- Validar manualmente os fluxos com usuario de teste por perfil.
- Fase 18 criou `actionRunner.ts` para centralizar `action -> refresh -> reset -> notify` em fluxos selecionados.
- Definir um padrao aprovado antes de migrar formularios transacionais para React Hook Form + Zod.

## Evolucao na Fase 18

- O padrao de feedback visual da Fase 17 foi preservado.
- `AdminPage.jsx` e `OperationalPages.jsx` agora usam wrappers locais que delegam para `runAction`.
- Resets foram movidos para `onSuccess` em fluxos representativos.
- Telas com upload, PA Map, chamados criticos e pausas foram mantidas fora da refatoracao para reduzir risco.
