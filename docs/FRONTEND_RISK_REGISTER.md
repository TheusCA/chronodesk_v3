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
| TanStack Table virar migracao ampla de tabelas criticas | Tables | Media | Alto | Fase 12 limita `useReactTable` a um componente somente leitura e `qa:table` valida escopo unico | Frontend/QA |
| Tabela client-side alterar contrato ou busca de dados | Tables/API | Baixa | Alto | TanStack Table usa dados ja carregados por `api()`/Query e nao busca dados diretamente | Frontend/AppSec |
| `fixed_weekdays` salvar dias invalidos ou vazios | Escala presencial | Media | Alto | Frontend valida selecao, backend normaliza ordem, rejeita vazio, rejeita duplicado e rejeita dia fora da lista | Full Stack/QA |
| Migration de ENUM falhar em ambiente divergente | Banco/MySQL | Baixa | Alto | Migration 010 usa `information_schema` e nao remove dados existentes; aplicar em janela controlada antes do deploy PHP | DevSecOps |
| RHF/Zod expandir para formulario sensivel sem padrao | Forms | Media | Alto | Fase 14 limita uso a filtros GET de `/relatorios`; `qa:forms` bloqueia mutations, POST novo e dependencias alternativas | Frontend/AppSec |
| Filtro de relatorios aplicar valor invalido | Forms/Zod | Baixa | Medio | `reportFiltersSchema` valida competencia, equipe e employee_id antes de atualizar `filters`; backend continua autoridade | Frontend/QA |

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
- Fase 12 iniciou TanStack Table apenas no resumo de `/relatorios`; proximas tabelas devem continuar somente leitura ate existir padrao aprovado para acoes e coluna de operacao.
- Fase 13 adicionou `fixed_weekdays`; validar manualmente um CI com segunda/quarta/sexta e outro com regra antiga antes do deploy amplo.
- Fase 14 introduziu React Hook Form + Zod apenas nos filtros de `/relatorios`; proximos formularios devem continuar um por fase e evitar POST sensivel ate existir padrao aprovado.
