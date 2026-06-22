# Portal SDK - TypeScript Migration Plan

Objetivo: adotar TypeScript gradualmente sem bloquear deploy e sem migrar telas criticas em bloco.

## Principios

- Manter `.jsx` funcionando durante toda a migracao.
- Usar `allowJs` no inicio para convivencia entre JS e TS.
- Comecar por utilitarios puros e componentes sem efeito colateral.
- Tipar contratos de API antes de refatorar chamadas.
- Tratar erros de tipo como guia de melhoria, nao como big bang.
- Deixar `App.jsx` e paginas densas por ultimo.

## Ordem recomendada

1. `frontend/src/lib/format.js`.
2. `frontend/src/lib/navigation.js`.
3. `frontend/src/lib/operational.js`.
4. Componentes puros de UI.
5. `States.jsx`.
6. `Primitives.jsx`.
7. Hooks como `useResource`.
8. Paginas pequenas ou somente leitura.
9. Paginas densas.
10. `App.jsx` por ultimo.

## Dependencias futuras recomendadas

Nao instaladas nesta fase:

```bash
cd frontend
npm install -D typescript
```

O projeto ja possui `@types/react` e `@types/react-dom`, entao a primeira instalacao pode ser menor que em um projeto novo.

## Configuracao futura sugerida

`tsconfig.json` inicial permissivo:

```json
{
  "compilerOptions": {
    "allowJs": true,
    "checkJs": false,
    "jsx": "react-jsx",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "noEmit": true,
    "strict": false,
    "target": "ES2020"
  },
  "include": ["src"]
}
```

Evolucao depois:

- `strict: true` somente apos tipos base.
- `checkJs: true` apenas se o ruido for aceitavel.
- `noUncheckedIndexedAccess` em fase posterior.

## Vite e ESLint

- Vite aceita TS/TSX quando `typescript` esta instalado.
- ESLint atual pode continuar para JS; regras TypeScript devem entrar em fase propria.
- Nao converter configs em conjunto com telas criticas.

## Tipos iniciais recomendados

- `Session`.
- `ApiError`.
- `ApiResponse<T>`.
- `Employee`.
- `ScheduleRuleType`.
- `WorkflowStatus`.
- `CriticalIncident`.
- `DocumentItem`.
- `ShiftAttachment`.

## Beneficios esperados

- Menos bug de payload (`rule_type`, `employee_id`, status).
- Melhor autocomplete em endpoints.
- Refatoracao mais segura em tabelas e formularios.
- Contratos frontend documentados em codigo.

## Riscos

- Tipos frouxos podem dar falsa sensacao de seguranca.
- Tipos rigidos demais podem atrasar deploy.
- Converter telas densas cedo aumenta risco de regressao.
- Tipos de resposta podem divergir do PHP se nao houver smoke.

## Rollback

- Reverter arquivos `.ts/.tsx` criados e `tsconfig.json`.
- Manter `.jsx` originais ate cada migracao estar aprovada.
- Nao misturar migracao TS com alteracao funcional.
