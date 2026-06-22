# Portal SDK - React Hook Form and Zod Plan

Objetivo futuro: reduzir bugs de formulario e payload sem substituir a validacao do backend.

Nenhuma dependencia foi instalada nesta fase.

## Dependencias futuras recomendadas

```bash
cd frontend
npm install react-hook-form zod
```

## Principios de adocao

- Backend continua sendo autoridade de regra de negocio.
- Zod valida formato, tipos e ergonomia antes do POST.
- React Hook Form reduz estado manual em formularios grandes.
- Payload final deve ser comparado com o payload atual antes de merge.
- Nao alterar nomes de campos enviados sem contrato aprovado.
- Migrar um formulario pequeno antes de escala, chamados criticos ou admin.

## Mapa de formularios

| Formulario | Validacoes atuais | Validacoes frontend seguras | Deve continuar no backend | Risco | Prioridade futura |
| --- | --- | --- | --- | --- | --- |
| Login AD | required, password | formato vazio, limpeza de senha no state | autenticacao AD, rate limit | Alto | P4 |
| Pausa/Reuniao | motivo e observacao | motivo permitido, tamanho da observacao | regras N1/N2, limites, jornada | Alto | P4 |
| Funcionarios | required, min/max, horarios | formato de horarios, equipe/perfil, id numerico | unicidade, permissao admin | Alto | P3 |
| Configuracoes | numeros, checkboxes | ranges e booleanos | regra operacional persistida | Alto | P3 |
| Escala presencial regra | required, date, rule_type | `rule_type` enum, employee_id numerico, data ISO | UPSERT, permissao, regra canonica | Muito alto | P2 com fixture |
| Excecao de escala | date, type, note | employee_id numerico, enum de tipo, data ISO | conflito e permissao | Alto | P3 |
| Importacao de escala | arquivo e preview | extensao visual e tamanho vindo do backend | MIME real, conteudo, limite final | Alto | P4 |
| Mapa de PA | employee, PA, date, notes | employee_id numerico, valid_from ISO | remoto/presencial, permissao, conflitos | Alto | P4 |
| Chamados criticos | muitos campos required/maxLength | status/severity enum, datas, URL, numeros | permissao, auditoria, normalizacao | Muito alto | P4 |
| Import War Room | arquivo e rows | tamanho visual e extensao | parsing final, cabecalhos, limites | Alto | P4 |
| Horas extras | datas, horas, motivo | horas validas, data ISO, employee_id | calculo total, aprovacao | Alto | P3 |
| Correcao de ponto | tipo, data, horas | regra de ausencia/hora, texto max | regra de aprovacao, status | Alto | P3 |
| Documentacao/upload | arquivo, titulo, categoria | titulo/categoria, visibility enum | extensoes, MIME, storage privado | Alto | P2 |
| Escalas de Sabado/upload | arquivo, titulo, mes | mes, titulo, notes | extensoes e storage | Alto | P2 |
| Calendario | titulo, datas, equipe | data inicial/final, tipo/status | permissao e persistencia | Medio | P2 |
| Plantonistas | periodo, horario, tipo | data/hora e employee_id | conflito e permissao | Medio | P2 |

## Ordem sugerida

1. Calendario ou plantonistas: formulario menor e sem upload.
2. Documentacao/upload: com cuidado, mantendo limites vindos do backend.
3. Escalas de Sabado/upload.
4. Horas extras ou correcao de ponto.
5. Funcionarios.
6. Escala presencial.
7. Mapa de PA.
8. Chamados criticos.
9. Pausas e login apenas com estrategia especifica.

## Padrao futuro recomendado

- `schema`: valida input de UI.
- `toPayload`: converte para contrato atual.
- `submit`: chama `post()` ou `postForm()` existente.
- `serverError`: exibe `mensagem` do backend sem stack trace.

Exemplo conceitual:

```ts
const parsed = schema.parse(formValues)
const payload = toPayload(parsed)
await post(endpoint, payload)
```

## Regras que nao devem ir para Zod

- Autenticacao AD.
- RBAC.
- CSRF.
- Auditoria.
- Verificacao real de MIME/conteudo.
- Regra de aprovacao N1/N2.
- Conflitos de escala/mapa.
- Calculo final de horas para persistencia.

## QA recomendado para cada migracao

- Snapshot textual do payload antigo e novo com fixture anonima.
- Smoke manual por perfil quando houver RBAC.
- `npm run qa:visual` com invariantes do payload critico.
- Backend smoke e lint PHP.
- Build Vite.
