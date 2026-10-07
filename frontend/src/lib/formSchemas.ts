import { z } from 'zod'

const scheduleWeekdaySchema = z.enum(['mon', 'tue', 'wed', 'thu', 'fri'], {
  error: 'Dia da semana invalido.',
})

export const CYCLE_MAX_DAYS = 20
const SCHEDULE_WEEKDAY_COUNT = 5

/** Dias de um bloco do ciclo: inteiro de 1 a 19 (so digitos), ou null. */
export function cycleDays(value: string): number | null {
  if (!/^\d{1,2}$/.test(value)) return null
  const days = Number(value)
  return days >= 1 && days <= CYCLE_MAX_DAYS - 1 ? days : null
}

/** Segunda a sexta, pela data do calendario (sem fuso: meio-dia UTC). */
export function isBusinessDay(date: string): boolean {
  const weekday = new Date(`${date}T12:00:00Z`).getUTCDay()
  return weekday >= 1 && weekday <= 5
}

const employeeIdSchema = z.preprocess(
  (value) => (value === '' || value === null || value === undefined ? undefined : Number(value)),
  z.number({ error: 'Informe o ID do funcionário.' })
    .int('ID inválido.')
    .min(1, 'ID deve ser maior que zero.')
    .max(999, 'ID deve ser no máximo 999.'),
)

const employeeTimeSchema = z.string({ error: 'Informe o horário.' })
  .min(1, 'Informe o horário.')
  .regex(/^([01]\d|2[0-3]):([0-5]\d)$/, 'Horário inválido.')

export const reportFiltersSchema = z.object({
  competency: z.string().min(1, 'Informe a competencia.').regex(/^\d{4}-\d{2}$/, 'Competencia invalida.'),
  team: z.enum(['', 'n1', 'n2']).default(''),
  employee_id: z.string().default(''),
})

export const employeeFormSchema = z.object({
  id: employeeIdSchema,
  nome: z.string({ error: 'Informe o nome do funcionário.' })
    .trim()
    .min(1, 'Informe o nome do funcionário.')
    .min(3, 'Nome deve ter no mínimo 3 caracteres.'),
  // listar_funcionarios.php devolve ad_login: null para quem não tem login AD vinculado.
  ad_login: z.preprocess(
    (value) => value ?? '',
    z.string({ error: 'Login AD inválido.' })
      .trim()
      .max(100, 'Login AD deve ter no máximo 100 caracteres.')
      .regex(/^[a-z0-9._@-]*$/i, 'Login AD contém caracteres inválidos.'),
  ),
  equipe: z.enum(['n1', 'n2', 'lideranca'], {
    error: 'Equipe inválida.',
  }),
  // Lote 5b: admin vem da allowlist AD_ADMIN_USERS e não é gravado no cadastro.
  access_role: z.enum(['tecnico', 'gestor', 'lideranca', 'somente_leitura'], {
    error: 'Perfil de acesso inválido.',
  }).default('tecnico'),
  jornada_entrada: employeeTimeSchema,
  jornada_saida: employeeTimeSchema,
  almoco_inicio: employeeTimeSchema,
  almoco_fim: employeeTimeSchema,
  ativo: z.boolean({ error: 'Status ativo inválido.' }).default(true),
  // F1 (P12): só admin altera; os demais reenviam o valor atual.
  fora_mapa_escala: z.boolean({ error: 'Valor inválido para "Fora do Mapa PA e da escala".' }).default(false),
}).superRefine((value, ctx) => {
  if (value.jornada_entrada >= value.jornada_saida) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['jornada_saida'],
      message: 'Horário de saída deve ser posterior à entrada.',
    })
  }

  if (value.almoco_inicio >= value.almoco_fim) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['almoco_fim'],
      message: 'Fim do almoço deve ser posterior ao início.',
    })
  }
})

export const scheduleRuleSchema = z.object({
  employee_id: z.string()
    .min(1, 'Selecione um colaborador.')
    .refine((value) => {
      const employeeId = Number(value)
      return Number.isInteger(employeeId) && employeeId > 0
    }, 'Colaborador invalido.'),
  rule_type: z.enum([
    'undefined',
    'even_days',
    'odd_days',
    'always_onsite',
    'always_remote',
    'fixed_weekdays',
    'cycle',
  ], {
    error: 'Tipo de escala invalido.',
  }),
  effective_from: z.string()
    .min(1, 'Informe a data de vigencia.')
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'Data de vigencia invalida.'),
  weekdays: z.array(scheduleWeekdaySchema).default([]),
  // F2B: dias fixos informados como presenciais (padrao) ou como home office.
  input_mode: z.enum(['onsite', 'home'], { error: 'Modo de informar os dias invalido.' }).default('onsite'),
  home_weekdays: z.array(scheduleWeekdaySchema).default([]),
  // F2B: ciclo. A data inicial e o effective_from (o servidor forca o inicio nela).
  cycle_onsite_days: z.string().default('2'),
  cycle_remote_days: z.string().default('1'),
  cycle_starts_with: z.enum(['onsite', 'remote'], { error: 'Informe se o ciclo comeca por dia presencial ou remoto.' }).default('onsite'),
}).superRefine((value, ctx) => {
  if (value.rule_type === 'fixed_weekdays' && value.input_mode === 'onsite' && value.weekdays.length === 0) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['weekdays'],
      message: 'Selecione ao menos um dia da semana.',
    })
  }
  // Espelha OperationalService::ruleDefinitionForPayload (o servidor valida).
  if (value.rule_type === 'fixed_weekdays' && value.input_mode === 'home') {
    if (value.home_weekdays.length === 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['home_weekdays'],
        message: 'Nenhum dia de home office marcado: para presencial em todos os dias uteis, use a regra "Sempre presencial".',
      })
    } else if (value.home_weekdays.length === SCHEDULE_WEEKDAY_COUNT) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['home_weekdays'],
        message: 'Todos os dias em home office: use a regra "Sempre remoto".',
      })
    }
  }
  if (value.rule_type === 'cycle') {
    const onsite = cycleDays(value.cycle_onsite_days)
    const remote = cycleDays(value.cycle_remote_days)
    if (onsite === null) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['cycle_onsite_days'], message: `Dias presenciais do ciclo: informe um numero inteiro de 1 a ${CYCLE_MAX_DAYS - 1}.` })
    }
    if (remote === null) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['cycle_remote_days'], message: `Dias remotos do ciclo: informe um numero inteiro de 1 a ${CYCLE_MAX_DAYS - 1}.` })
    }
    if (onsite !== null && remote !== null && onsite + remote > CYCLE_MAX_DAYS) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['cycle_remote_days'], message: `O ciclo pode ter no maximo ${CYCLE_MAX_DAYS} dias.` })
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(value.effective_from) && !isBusinessDay(value.effective_from)) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['effective_from'], message: 'A data inicial do ciclo precisa ser dia util (segunda a sexta).' })
    }
  }
})

export type ReportFiltersFormValues = z.infer<typeof reportFiltersSchema>
export type EmployeeFormValues = z.infer<typeof employeeFormSchema>
export type ScheduleRuleFormValues = z.infer<typeof scheduleRuleSchema>
