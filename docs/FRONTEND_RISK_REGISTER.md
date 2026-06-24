# Portal SDK - Frontend Risk Register

| Risco | Area | Probabilidade | Impacto | Mitigacao | Dono sugerido |
| --- | --- | --- | --- | --- | --- |
| Big bang migration para TS quebrar telas criticas | TypeScript | Media | Alto | Migrar utilitarios e componentes puros primeiro | Frontend |
| Cache stale em aprovacao ou pausa | TanStack Query | Media | Alto | Query keys explicitas, invalidacao apos mutacao, smoke por perfil | Frontend/QA |
| `rule_type` incorreto em escala presencial | API/payload | Media | Muito alto | Tipo union em `operational.ts` e teste estatico de payload | Frontend/QA |
| Formulario grande alterar payload de chamados criticos | Forms | Media | Alto | Zod apenas no adapter, comparar payload antigo/novo | Frontend |
| Column visibility esconder acao critica | Tables | Baixa | Alto | Colunas de acao fixas e nao ocultaveis | UX/Frontend |
| Validacao frontend divergir do backend | Forms/Zod | Media | Medio | Frontend valida ergonomia; backend continua autoridade | AppSec |
| Upload aceitar extensao diferente na UI | Uploads | Baixa | Alto | Limites sempre vindos do backend, nao hardcode novo | AppSec |
| Erro tecnico vazar SQL/path/stack | AppSec | Baixa | Alto | QA estatico e sanitizacao backend preservada | AppSec |
| POC ser importada acidentalmente | Fase 6 | Baixa | Medio | Script `qa:visual` valida que `frontend/poc` nao e importado | DevSecOps |
| Dependencia nova entrar sem plano | Supply chain | Media | Medio | QA estatico valida dependencias proibidas nesta fase | DevSecOps |
| TS permissivo nao capturar erro em JSX | TypeScript | Media | Medio | `allowJs` preserva convivencia; migrar arquivos por prioridade e manter QA/build | Frontend |
| Import futuro usar extensao `.js` para utilitario migrado | TypeScript | Baixa | Medio | `qa:operational` e `qa:visual` exigem `operational.ts` e ausencia de `operational.js` | Frontend |
| Migracao do transporte API remover CSRF ou cookies | API/AppSec | Baixa | Muito alto | `qa:api` valida CSRF, `credentials: same-origin`, JSON e FormData | Frontend/AppSec |
| 401 deixar de disparar evento global | Sessao | Baixa | Alto | `qa:api` valida `chronodesk:unauthorized` exceto em `session.php` | Frontend/QA |
| Cache de dados operacionais sensiveis ficar agressivo | TanStack Query | Media | Alto | Fase 10 usa `retry=false`, `refetchOnWindowFocus=false`, `staleTime=30_000` e apenas `/relatorios` | Frontend/AppSec |
| Mutacoes entrarem em TanStack Query cedo demais | TanStack Query | Media | Alto | `qa:query` bloqueia `useMutation` nesta fase | Frontend/QA |
| Hook legado perder cleanup ou controle de request obsoleto | TypeScript | Baixa | Alto | `qa:resource` valida `requestRef`, interval cleanup e listener de visibilidade | Frontend/QA |

## Pendencias antes de migracoes reais

- Definir ambiente de smoke autenticado por perfil.
- Criar fixtures anonimizadas de payload para schedules, workflows e critical incidents.
- Aprovar dependencias e versoes antes de instalar.
- Definir politica de cache por tela.
- Definir padrao de erro exibido para usuario.
- Fase 8 concluiu a migracao de `lib/operational.ts`; proximas migracoes devem continuar pequenas e sem paginas densas.
- Fase 9 concluiu a migracao de `lib/api.ts`; introducao de TanStack Query deve manter `api()` como transporte base.
- Fase 10 iniciou TanStack Query apenas em `/relatorios`; proximos passos devem manter uma tela por vez e evitar mutacoes ate definir invalidacao.
- Fase 11 concluiu a migracao de `useResource.ts`; consumidores continuam JSX e devem ser migrados apenas em fases pequenas.
