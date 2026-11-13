# Protótipo R0 do redesenho

Três telas estáticas, **fora do app e do build de produção**:
- Pausas, na visão do técnico;
- Visão operacional, na visão da Liderança;
- Administração > Funcionários, na visão da Liderança.

O Vite só empacota o que o `src/` importa, e nada importa esta pasta. A direção e a auditoria estão em `docs/design/`.

- **Dados:** só fictícios ("Técnico NN Exemplo", "tecnico.NN"). Nenhuma chamada de rede, nenhum armazenamento no navegador.
- **Mesma regra da CSP do portal** (`style-src 'self'`, `script-src 'self'`):
  - nenhum atributo `style`;
  - nenhum recurso externo;
  - fontes auto-hospedadas em `fontes/`.
- **Ícones:** os traços do `src/components/ui/Icon.jsx`, copiados. Nenhuma biblioteca nova.

## Como abrir

Os arquivos usam caminho relativo. Para ver com o servidor do script:

```powershell
node prototypes/r0/capturar.mjs depois $env:TEMP\r0
```

Ou abra `pausas.html` direto no navegador.

**Parâmetros da URL:**

| Parâmetro | Valores | Padrão |
|---|---|---|
| `direcao` | `a` (Mesa clara) ou `b` (Sala de controle) | `a` |
| `tema` | `claro` ou `escuro` | claro na A, escuro na B |
| `estado=ativa` | Pausas com a pausa em andamento | |
| `painel=editar` | Funcionários com o painel de edição aberto | |
| `congelar=1` | Relógio parado em 10:42:05 | |

## Scripts

Os scripts são Node, só para a estação. Nenhum entra nos `qa:*`.

| Comando (na pasta `frontend`) | O que faz |
|---|---|
| `node prototypes/r0/capturar.mjs antes <saída>` | Capturas do app atual (build em `../app`) pelo servidor simulado do roteiro de navegador, por perfil, em 1280 e 390 px. Um proxy troca só a sessão e enche a situação da equipe com mais técnicos fictícios; o `mock-server.mjs` não muda. |
| `node prototypes/r0/capturar.mjs depois <saída>` | Capturas do protótipo: 3 telas × 2 direções × 2 temas × 2 larguras, mais os dois estados (pausa ativa e edição aberta). |
| `node prototypes/r0/capturar.mjs verificar` | Em 390 px: alvo de toque abaixo de 40 px e rolagem lateral, nas 12 combinações. Sai 1 se houver problema. |
| `node prototypes/r0/contraste.mjs [--md]` | Contraste WCAG dos tokens nos quatro temas, lido do `prototipo.css`. Sai 1 se algum par falhar. |

Os scripts de captura precisam de `CHROME_PATH`, como o roteiro de navegador.

## Fontes

Registradas em `fontes/README.md`: as quatro famílias, as licenças OFL 1.1 e a origem.
