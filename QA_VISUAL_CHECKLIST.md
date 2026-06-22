# Portal SDK - QA Visual e Smoke por Perfil

Checklist objetivo para a Fase 5. Use em homologacao local/controlada antes de commit ou deploy.

## Perfis minimos

- [ ] Admin/Gestor autenticado via fluxo existente.
- [ ] Tecnico autenticado via fluxo existente.
- [ ] Perfil sem permissao valida recebe bloqueio sem dados sensiveis.

## Validacao global

- [ ] Tela carrega sem erro persistente no console.
- [ ] Network sem erro 500 recorrente.
- [ ] Layout nao estoura em notebook.
- [ ] Layout nao estoura em mobile basico.
- [ ] Tabelas longas possuem scroll horizontal.
- [ ] Cards com nomes/textos longos nao quebram a pagina.
- [ ] Botoes de acao ficam alinhados e acessiveis por teclado.
- [ ] Foco visivel aparece em links, botoes, inputs e selects.
- [ ] Estados loading, vazio e erro fazem sentido.
- [ ] Acoes restritas aparecem apenas para perfil autorizado.
- [ ] Nenhum path interno, stack trace, SQL, token ou segredo aparece na UI.

## Dashboard

- [ ] Metricas carregam e permanecem legiveis.
- [ ] Cards de pausas ativas nao estouram com nomes longos.
- [ ] Atualizacao automatica nao duplica notificacoes ou cards.
- [ ] Estado vazio orienta sem expor detalhe interno.
- [ ] Admin/Gestor ve acoes administrativas quando permitido.
- [ ] Tecnico nao ve acao administrativa de derrubar pausa.

## Pausas

- [ ] Card da propria pausa exibe status e timers corretamente.
- [ ] Cards N1/N2 mantem legibilidade com muitas pessoas.
- [ ] Observacao de reuniao quebra linha sem overflow.
- [ ] Reuniao N1 continua solicitando aprovacao conforme regra atual.
- [ ] Reuniao N2, cafe e pausa pessoal seguem regras atuais.
- [ ] Admin pode derrubar pausa quando permitido.
- [ ] Tecnico nao ve botao de derrubar pausa.

## Admin e Aprovacoes

- [ ] Fila vazia aparece clara.
- [ ] Pausas pendentes exibem aprovar/rejeitar alinhados.
- [ ] Horas extras pendentes exibem aprovar/rejeitar alinhados.
- [ ] Correcoes de ponto pendentes exibem aprovar/rejeitar alinhados.
- [ ] Funcionarios filtram visualmente sem quebrar tabela.
- [ ] Modal de edicao nao estoura altura da tela.
- [ ] Configuracoes preservam labels e mensagens de seguranca.

## Horas Extras

- [ ] Filtros de periodo, equipe e status cabem em notebook.
- [ ] Nova solicitacao permanece legivel.
- [ ] Calculo total continua exibido.
- [ ] Pendentes e historico ficam separados visualmente.
- [ ] Exportacao CSV continua disponivel para perfil permitido.

## Correcao de Ponto

- [ ] Filtros cabem em notebook e mobile basico.
- [ ] Nova solicitacao exibe horario correto e registrado.
- [ ] Pendentes e historico ficam legiveis.
- [ ] Exportacao CSV continua disponivel para perfil permitido.
- [ ] Status internos nao aparecem como texto cru quando houver label.

## Escala Presencial

- [ ] Regra por colaborador preserva valores canonicos.
- [ ] Dias pares envia `rule_type=even_days`.
- [ ] Dias impares envia `rule_type=odd_days`.
- [ ] Sempre presencial envia `rule_type=always_onsite`.
- [ ] Sempre remoto envia `rule_type=always_remote`.
- [ ] Sem escala definida preserva `undefined`.
- [ ] Excecao por data nao estoura o formulario.
- [ ] Importacao exibe preview e erros sem quebrar layout.
- [ ] Lista de regras ativas possui scroll horizontal.
- [ ] Calendario gerado continua legivel.

## Mapa de PA

- [ ] Grid possui scroll horizontal quando necessario.
- [ ] Legenda nao depende apenas de cor.
- [ ] Filtro por data/equipe carrega sem erro.
- [ ] PA livre e PA ocupado ficam distinguiveis.
- [ ] Modal de edicao cabe na tela e rola verticalmente.
- [ ] Tecnico visualiza sem acoes de edicao.
- [ ] Admin/Gestor edita quando permitido.

## Chamados Criticos

- [ ] Metricas cabem em duas linhas sem sobreposicao.
- [ ] Filtros longos quebram sem estourar.
- [ ] Listagem tem scroll horizontal.
- [ ] Formulario cabe e rola em notebook.
- [ ] Importacao War Room exibe preview antes de confirmar.
- [ ] Detalhe mostra textos longos com quebra.
- [ ] Exportacao CSV continua disponivel.
- [ ] Tempos calculados continuam informativos.

## Escalas de Sabado

- [ ] Upload abre e fecha por mouse e teclado.
- [ ] Formatos aceitos continuam PNG, JPG, JPEG, PDF, CSV, XLS e XLSX.
- [ ] Feed nao estoura com nomes longos de arquivo.
- [ ] Preview de imagem/PDF/planilha continua acessivel.
- [ ] Download usa endpoint autenticado existente.
- [ ] Filtros cabem em notebook.

## Documentacao

- [ ] Filtros de busca, tipo, categoria, responsavel e data cabem em notebook.
- [ ] Upload abre e fecha por mouse e teclado.
- [ ] Cards nao exibem caminho fisico interno.
- [ ] Download usa endpoint autenticado existente.
- [ ] Remocao aparece apenas para perfil autorizado.
- [ ] Visibilidade de gestao fica sinalizada por texto e cor.

## Calendario

- [ ] Alternancia mes/semana nao quebra layout.
- [ ] Filtros de equipe, colaborador, tipo e status cabem em notebook.
- [ ] Novo evento aparece apenas para perfil autorizado.
- [ ] Eventos longos usam truncamento sem sobrepor conteudo.
- [ ] Responsividade basica preserva leitura dos dias.

## AppSec frontend

- [ ] `rg "dangerouslySetInnerHTML|innerHTML|localStorage" frontend/src` sem ocorrencias.
- [ ] Nenhum `.env`, dump, ZIP, backup, CSV, XLSX ou DOCX novo foi criado.
- [ ] Nenhum segredo, token, SQL, stack trace ou path interno foi adicionado.
- [ ] Nenhuma renderizacao de HTML vindo do backend foi adicionada.
