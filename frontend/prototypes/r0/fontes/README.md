# Fontes do protótipo R0

Todas sob a **SIL Open Font License 1.1**. O texto de cada licença está ao lado, em `LICENSE-<família>.txt`. Só o subconjunto latino (cobre o português), em woff2, carregado com `font-display: swap`.

**Origem:** os pacotes `@fontsource/*` na versão 5.3.0, publicados em 2026-07-19. Foram baixados do registro npm só como arquivo (`npm pack`), no scratchpad da sessão, e copiados para cá. **Nenhuma dependência entrou no `package.json` nem no lockfile.**

| Família | Pesos | Arquivos | Direção |
|---|---|---|---|
| IBM Plex Sans | 400, 500, 600 | `ibm-plex-sans-latin-*-normal.woff2` | A (texto) |
| IBM Plex Mono | 500, 600 | `ibm-plex-mono-latin-*-normal.woff2` | A (números e horários) |
| Atkinson Hyperlegible | 400, 700 | `atkinson-hyperlegible-latin-*-normal.woff2` | B (texto) |
| JetBrains Mono | 500, 700 | `jetbrains-mono-latin-*-normal.woff2` | B (números e horários) |

**Total:** cerca de 205 KB nos nove arquivos.

**No app (R1),** só entra a família da direção escolhida, com os pesos usados. O registro vai junto, com a licença e esta origem.
