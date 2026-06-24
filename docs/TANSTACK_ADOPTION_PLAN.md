# Portal SDK - TanStack Adoption Plan

Este plano cobre TanStack Query e TanStack Table. As dependencias devem entrar por fases pequenas, com uma tela ou tabela por vez.

## TanStack Query

### Dependencia

```bash
cd frontend
npm install @tanstack/react-query
```

Status: instalada na Fase 10.

### Padrao inicial

- Manter `api()`, `post()` e `postForm()` como transporte.
- Adicionar `QueryClientProvider` no topo apenas em fase futura.
- Criar `src/lib/queryKeys.ts` ou `.js` primeiro.
- Migrar uma tela pequena antes de dashboard/pausas/admin.
- Preservar CSRF porque mutacoes continuam usando `post()`/`postForm()`.
- Preservar tratamento 401/403 porque `api()` continua disparando `chronodesk:unauthorized`.

### Candidatos por prioridade

| Tela | GET atual | Beneficio | Risco | Prioridade |
| --- | --- | --- | --- | --- |
| Documentacao | `portal/documents.php` | Cache simples e refresh apos upload/delete | Upload | P1 |
| Escalas de Sabado | `portal/shift_attachments.php` | Refresh apos upload | Preview/download | P1 |
| Metricas | `metricas.php` | Cache por filtros | Exportacao | P2 |
| Dashboard | `portal/dashboard.php` | Refetch padronizado | Polling operacional | P3 |
| Mapa de PA | `portal/pa_map.php` | Cache por data/equipe | Confirmacao remoto | P3 |
| Escala Presencial | `portal/schedules.php` | Query keys por filtro | Payload `rule_type` | P4 |
| Admin/Aprovacoes | varios endpoints | Invalidate por aba | RBAC/admin | P4 |
| Chamados Criticos | `portal/critical_incidents.php` | Cache por filtros e detalhe | Formulario grande | P4 |
| Pausas | `status.php` | Polling central | Timers e acao critica | P5 |

### Query keys propostas

- `['session']`
- `['dashboard']`
- `['pauses', 'live']`
- `['documents', filters]`
- `['shiftAttachments', filters]`
- `['schedules', filters]`
- `['paMap', filters]`
- `['criticalIncidents', filters]`
- `['criticalIncident', id]`
- `['workflow', 'overtime', filters]`
- `['workflow', 'timeCorrections', filters]`

### Riscos

- Cache stale mostrando decisao antiga de aprovacao.
- Refetch automatico duplicando notificacoes.
- Mutacao sem invalidar a query correta.
- Tratamento de 401 divergente do `api()` atual.

## TanStack Table

### Dependencia

```bash
cd frontend
npm install @tanstack/react-table
```

Status: instalada na Fase 12.

### Tabelas candidatas

| Tabela | Ordenacao | Paginacao | Filtros | Column visibility | Recomendacao |
| --- | --- | --- | --- | --- | --- |
| Funcionarios | Sim | Sim | Sim | Sim | Alto ganho |
| Aprovacoes | Sim | Nao inicialmente | Sim | Nao | Manter simples primeiro |
| Horas extras | Sim | Sim | Sim | Sim | Alto ganho |
| Correcao de ponto | Sim | Sim | Sim | Sim | Alto ganho |
| Escala presencial | Sim | Sim | Sim | Sim | Alto risco por regras |
| Chamados criticos | Sim | Sim | Sim | Sim | Alto ganho, migrar depois |
| Documentacao | Sim | Opcional | Sim | Opcional | Cards podem continuar |
| Metricas | Sim | Sim | Sim | Nao | Medio ganho |
| Relatorios | Sim | Nao | Sim | Nao | Baixo risco |

Primeira adocao concluida na Fase 12: resumo de `/relatorios`, usando `ReportsSummaryTable` apenas como camada de renderizacao sobre dados ja obtidos por TanStack Query e `api()`.

### O que deve permanecer simples

- Cards de Dashboard.
- Cards de Pausas.
- Grid do Mapa de PA.
- Feed de Escalas de Sabado.
- Cards de Documentacao, enquanto a experiencia atual for mais util que tabela.

### Impacto visual

- Manter `data-table`, `table-wrap`, `status-badge` e a linguagem visual atual.
- Evitar que TanStack Table introduza UI propria; usar apenas motor de tabela.
- Preservar scroll horizontal.

### Riscos

- Colunas escondidas podem ocultar acao critica.
- Ordenacao client-side pode confundir quando backend ja filtra periodo.
- Paginacao client-side pode esconder pendencias.
- Migrar tabela e contrato ao mesmo tempo aumenta risco.
