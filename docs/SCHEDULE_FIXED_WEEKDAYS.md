# Portal SDK - Escala Fixa Por Dias Da Semana

## Status

Fase 13 adicionou suporte ao `rule_type` `fixed_weekdays` para escala presencial fixa por dias da semana, preservando as regras existentes. Fase 20 manteve o mesmo contrato e migrou somente o formulario de regra para React Hook Form + Zod.

## Regras Preservadas

- `even_days`: presencial em dias pares.
- `odd_days`: presencial em dias impares.
- `always_onsite`: sempre presencial.
- `always_remote`: sempre remoto.
- `undefined`: sem escala definida.

## Nova Regra

Payload aceito:

```json
{
  "action": "rule",
  "employee_id": 123,
  "rule_type": "fixed_weekdays",
  "effective_from": "2026-06-24",
  "weekdays": ["mon", "wed", "fri"]
}
```

Dias aceitos inicialmente:

- `mon`: segunda.
- `tue`: terca.
- `wed`: quarta.
- `thu`: quinta.
- `fri`: sexta.

Fim de semana nao foi exposto na UI da Fase 13.

## Comportamento

Quando `rule_type = fixed_weekdays`:

- se a data cair em um dos dias de `weekdays`, o colaborador aparece como presencial;
- se a data nao cair em `weekdays`, o colaborador aparece como remoto;
- `weekdays` vazio, duplicado ou com valor invalido e rejeitado pelo backend.

Exemplo `["mon", "wed", "fri"]`:

- segunda: presencial;
- terca: remoto;
- quarta: presencial;
- quinta: remoto;
- sexta: presencial.

## Persistencia

Migration criada:

```text
migrations/20260624_010_schedule_fixed_weekdays.sql
```

Alteracoes:

- adiciona `fixed_weekdays` ao ENUM `portal_schedule_rules.rule_type`;
- adiciona `portal_schedule_rules.rule_config TEXT`;
- adiciona `fixed_weekdays` ao ENUM `portal_pa_assignments.schedule_rule_type`;
- adiciona `portal_pa_assignments.schedule_rule_config TEXT`.

O JSON serializado fica em `rule_config`, por exemplo:

```json
{"weekdays":["mon","wed","fri"]}
```

A migration e idempotente e usa `information_schema` para evitar ALTER repetido.

## Aplicacao Na VM

Aplicar antes do deploy PHP/JS:

```bash
mysql -u <usuario> -p <banco> < migrations/20260624_010_schedule_fixed_weekdays.sql
```

Nao usar credenciais no codigo ou em historico de shell compartilhado.

## Frontend

Na tela de escala presencial:

- selecionar `Dias fixos da semana`;
- marcar segunda a sexta conforme necessario;
- salvar.

Para editar, usar o botao `Editar` na regra ativa; os dias salvos sao carregados no formulario.

Para limpar, alterar para outra regra ou remover a regra. O backend grava `rule_config = NULL` para regras antigas e na remocao.

Desde a Fase 20:

- `scheduleRuleSchema` exige `weekdays` quando `rule_type = fixed_weekdays`;
- ao selecionar uma regra diferente, o formulario limpa `weekdays`;
- o payload continua enviando `weekdays` somente para `fixed_weekdays`;
- apos salvar, o formulario limpa colaborador, regra e weekdays, preservando a data de vigencia util.

## Rollback

Rollback funcional:

- voltar a selecionar uma regra antiga para cada colaborador com `fixed_weekdays`;
- ou remover a regra ativa.

Rollback tecnico:

- reverter codigo da Fase 13;
- garantir que nao existam linhas com `rule_type = 'fixed_weekdays'`;
- em janela controlada, remover `fixed_weekdays` dos ENUMs e remover as colunas de configuracao somente se nao houver dependencia operacional.

## Riscos Residuais

- O Mapa de PA armazena snapshot da configuracao no vinculo recorrente; alteracoes futuras de regra podem exigir realocacao/edicao do vinculo para refletir snapshot antigo.
- Fim de semana ficou fora do escopo inicial.
- Nao ha fixture de banco real no QA local; a validacao automatizada cobre sintaxe, contrato e invariantes estaticos.
