# Frontend Code Splitting

## Fase 15

Code splitting controlado no frontend do Portal SDK usando recursos nativos do React e Vite.

## Motivo

Apos a Fase 14, o build passou, mas o JS principal ficou acima do limiar de 500 kB:

```txt
index-6fubdv2P.js   500.74 kB | gzip: 140.62 kB
```

O objetivo foi reduzir o bundle inicial sem mascarar o warning com `chunkSizeWarningLimit` e sem alterar regras de negocio.

## Estrategia

- `React.lazy` aplicado em paginas autenticadas.
- `Suspense` adicionado em volta do conteudo renderizado dentro de `PortalLayout`.
- Fallback reutiliza `LoadingState` existente.
- Login, sessao, logout, layout, feedback, router, `useLivePauses` e transporte API continuam carregados diretamente.
- Nenhuma dependencia nova foi instalada.
- `vite.config.js` nao foi alterado.

## Paginas lazy-loaded

- Dashboard
- Pausas
- Admin
- Metricas
- Calendario
- Escala presencial
- Mapa de PA
- Escala de sabado
- Horas extras
- Correcao de ponto
- Plantonistas
- Chamados criticos
- Relatorios
- Documentacao
- Configuracoes
- Modulos genericos

As paginas agrupadas em `OperationalPages.jsx` permanecem no mesmo modulo, gerando um chunk compartilhado para esses fluxos.

## Medicao

Antes:

```txt
index-6fubdv2P.js   500.74 kB | gzip: 140.62 kB
warning de chunk > 500 kB
```

Depois:

```txt
index-BIXqcBsL.js                   199.16 kB | gzip: 63.97 kB
OperationalPages-BlMDhSTK.js        177.42 kB | gzip: 48.48 kB
AdminPage-Dkx8vuKz.js                33.15 kB | gzip:  8.76 kB
CriticalIncidentsPage-DK1i_Zsz.js    25.70 kB | gzip:  6.57 kB
```

O warning de 500 kB foi eliminado por code splitting real.

## QA

Criado `frontend/scripts/qa-bundle.mjs` e script `qa:bundle`.

O QA valida:

- uso de `lazy` e `Suspense`;
- fallback com `LoadingState`;
- multiplas paginas carregadas por import dinamico;
- ausencia de imports estaticos das paginas principais no `App.jsx`;
- ausencia de `chunkSizeWarningLimit`;
- nenhuma dependencia nova;
- `fetch()` apenas em `api.ts`;
- `useLivePauses.js`, `api.ts`, `useResource.ts`, `queryClient.ts`, `queryKeys.ts` e `operational.ts` sem alteracao;
- ausencia de mutation, `.tsx` runtime e paginas TypeScript.

## Rollback

1. Restaurar imports estaticos das paginas no `App.jsx`.
2. Remover helper `lazyPage` e o `Suspense` em volta do conteudo.
3. Remover `frontend/scripts/qa-bundle.mjs`.
4. Remover `qa:bundle` do `frontend/package.json`.
5. Reverter atualizacoes em `qa:visual` e docs.

## Riscos residuais

- Primeiro acesso a uma pagina pode baixar um chunk adicional.
- O chunk `OperationalPages` ainda concentra varios fluxos operacionais; pode ser separado em fase posterior apenas se houver necessidade real.
- Sem Error Boundary dedicado para erro de carregamento de chunk; o comportamento atual nao adiciona tratamento novo.

## Proximos passos

- Validar manualmente navegacao entre todas as telas principais.
- Considerar separar `OperationalPages.jsx` em modulos menores em fase futura, sem alterar contratos de API.
- Avaliar prefetch leve apenas se houver evidencia de latencia perceptivel.
