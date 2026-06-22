# Portal SDK - Frontend Risk Register

| Risco | Area | Probabilidade | Impacto | Mitigacao | Dono sugerido |
| --- | --- | --- | --- | --- | --- |
| Big bang migration para TS quebrar telas criticas | TypeScript | Media | Alto | Migrar utilitarios e componentes puros primeiro | Frontend |
| Cache stale em aprovacao ou pausa | TanStack Query | Media | Alto | Query keys explicitas, invalidacao apos mutacao, smoke por perfil | Frontend/QA |
| `rule_type` incorreto em escala presencial | API/payload | Media | Muito alto | Tipo union, teste estatico, fixture de payload | Frontend/QA |
| Formulario grande alterar payload de chamados criticos | Forms | Media | Alto | Zod apenas no adapter, comparar payload antigo/novo | Frontend |
| Column visibility esconder acao critica | Tables | Baixa | Alto | Colunas de acao fixas e nao ocultaveis | UX/Frontend |
| Validacao frontend divergir do backend | Forms/Zod | Media | Medio | Frontend valida ergonomia; backend continua autoridade | AppSec |
| Upload aceitar extensao diferente na UI | Uploads | Baixa | Alto | Limites sempre vindos do backend, nao hardcode novo | AppSec |
| Erro tecnico vazar SQL/path/stack | AppSec | Baixa | Alto | QA estatico e sanitizacao backend preservada | AppSec |
| POC ser importada acidentalmente | Fase 6 | Baixa | Medio | Script `qa:visual` valida que `frontend/poc` nao e importado | DevSecOps |
| Dependencia nova entrar sem plano | Supply chain | Media | Medio | QA estatico valida dependencias proibidas nesta fase | DevSecOps |

## Pendencias antes de migracoes reais

- Definir ambiente de smoke autenticado por perfil.
- Criar fixtures anonimizadas de payload para schedules, workflows e critical incidents.
- Aprovar dependencias e versoes antes de instalar.
- Definir politica de cache por tela.
- Definir padrao de erro exibido para usuario.
