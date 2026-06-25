# Frontend UX Hardening

## Fase 16

Hardening pequeno de UX pos-code splitting.

## Motivo

A Fase 15 passou a carregar paginas autenticadas sob demanda com `React.lazy` e `Suspense`. A Fase 16 adiciona uma camada de protecao para falha de carregamento de chunk lazy e registra um credito discreto do desenvolvedor no frontend.

## Error Boundary

Criado `frontend/src/components/RouteErrorBoundary.jsx`.

Comportamento:

- captura falha de renderizacao/carregamento da pagina lazy;
- exibe mensagem profissional sem stack trace;
- oferece botao `Tentar novamente`;
- o botao recarrega a pagina com `window.location.reload()`;
- reseta o erro ao trocar de rota por `resetKey`;
- nao envia dados para servico externo;
- nao usa `console`;
- nao chama API.

O conteudo lazy em `App.jsx` ficou protegido assim:

```jsx
<RouteErrorBoundary resetKey={router.path}>
  <Suspense fallback={<LoadingState label="Carregando modulo..." />}>
    {content}
  </Suspense>
</RouteErrorBoundary>
```

## Credito do desenvolvedor

Texto usado:

```txt
Desenvolvido por Matheus Camargo
```

Locais:

- tela de login, abaixo do card de autenticacao;
- rodape da sidebar autenticada quando expandida;
- `title` no rodape da sidebar compacta.

O credito usa texto pequeno, cor neutra e nao adiciona imagem, animacao ou dependencia.

## Impacto visual

- Login recebe uma linha discreta abaixo do formulario.
- Portal autenticado recebe uma linha discreta no rodape da sidebar expandida.
- Sidebar minimizada preserva espaco e usa identificacao por `title`.

## Fora do escopo

- backend;
- banco;
- autenticacao;
- CSRF;
- RBAC;
- auditoria;
- regras de pausa;
- `fixed_weekdays`;
- endpoints;
- payloads;
- querystrings;
- monitoramento externo.

## QA

Criado `frontend/scripts/qa-ux-hardening.mjs`.

O QA valida:

- existencia do Error Boundary;
- `App.jsx` envolvendo `Suspense` com o boundary;
- fallback com `LoadingState`;
- credito no login e em area autenticada;
- ausencia de `localStorage`, `innerHTML` e `dangerouslySetInnerHTML`;
- ausencia de dependencia nova/proibida;
- `fetch()` centralizado em `api.ts`;
- arquivos criticos sem alteracao;
- ausencia de mutation, `.tsx` runtime e paginas TypeScript.

## Rollback

1. Remover `RouteErrorBoundary.jsx`.
2. Remover wrapper `RouteErrorBoundary` de `App.jsx`.
3. Remover credito de `LoginPage.jsx` e `PortalLayout.jsx`.
4. Remover `qa:ux-hardening` e `frontend/scripts/qa-ux-hardening.mjs`.
5. Reverter ajustes em `qa:visual` e docs.

## Riscos residuais

- O retry recarrega a pagina inteira; e simples e robusto, mas nao tenta recuperar o chunk sem reload.
- Sem telemetria externa para falha de chunk, por decisao de escopo e AppSec.
- Teste manual de falha real de chunk ainda depende de simulacao no navegador ou ambiente controlado.

## Proximos passos

- Validar manualmente falha de chunk bloqueando um JS no navegador em ambiente seguro.
- Considerar Error Boundary mais granular apenas se houver evidencia de falhas frequentes.
- Definir padrao de contato/suporte caso o credito precise evoluir para link interno.
