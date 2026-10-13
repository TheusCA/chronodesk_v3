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
  // F4 (D4): ativo não é campo do formulário; arquivar e restaurar são ações da linha.
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

// F3 (RF-04): limites de services/AbsenceRules.php. O servidor valida de novo
// e decide (jornada atual, sobreposição, Liderança, cadastro inativo).
export const ABSENCE_MAX_DAYS = 366
export const ABSENCE_NOTE_MAX_CHARS = 500
export const ABSENCE_CATEGORIES = [
  { value: 'folga', label: 'Folga' },
  { value: 'ferias', label: 'Férias' },
  { value: 'ausencia_justificada', label: 'Ausência justificada' },
  { value: 'compromisso', label: 'Compromisso' },
  { value: 'outro', label: 'Outro' },
] as const
export const ABSENCE_RETROACTIVE_MESSAGE = 'Data anterior a hoje: só administradores e Liderança lançam ausência retroativa.'

const ABSENCE_TIME = /^([01]\d|2[0-3]):[0-5]\d$/
// Jornada vinda do cadastro: 'HH:MM' ou 'HH:MM:SS'.
const STORED_TIME = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/

/** Data do calendário em YYYY-MM-DD (recusa 2026-02-30). */
export function isCalendarDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const date = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
}

/** Dias da ausência, contando o primeiro e o último. */
export function absenceDays(startsOn: string, endsOn: string): number {
  return Math.round((Date.parse(`${endsOn}T00:00:00Z`) - Date.parse(`${startsOn}T00:00:00Z`)) / 86400000) + 1
}

export type AbsenceFormContext = {
  /** Hoje, YYYY-MM-DD. */
  today: string
  /** ausencias.retroativa (Liderança e admin). */
  canRetroactive: boolean
  /** Jornada do técnico escolhido; ausente quando a tela não a conhece (o servidor confere). */
  workday?: { start: unknown, end: unknown } | null
}

export function absenceFormSchema({ today, canRetroactive, workday = null }: AbsenceFormContext) {
  return z.object({
    employee_id: z.string({ error: 'Selecione o técnico.' })
      .min(1, { error: 'Selecione o técnico.', abort: true })
      .refine((value) => /^\d{1,10}$/.test(value) && Number(value) > 0, 'Técnico inválido.'),
    starts_on: z.string({ error: 'Informe a data inicial.' })
      .min(1, { error: 'Informe a data inicial.', abort: true })
      .refine(isCalendarDate, 'Data inicial inválida.'),
    ends_on: z.string({ error: 'Informe a data final.' })
      .min(1, { error: 'Informe a data final.', abort: true })
      .refine(isCalendarDate, 'Data final inválida.'),
    period_type: z.enum(['full_day', 'from_time', 'interval'], { error: 'Tipo de período inválido.' }),
    start_time: z.string().default(''),
    end_time: z.string().default(''),
    category: z.enum(['folga', 'ferias', 'ausencia_justificada', 'compromisso', 'outro'], { error: 'Selecione a categoria.' }),
    // Caracteres como o servidor conta (pontos de código UTF-8), não unidades UTF-16.
    note: z.string().default('').transform((value) => value.trim())
      .refine((value) => Array.from(value).length <= ABSENCE_NOTE_MAX_CHARS, `A observação pode ter no máximo ${ABSENCE_NOTE_MAX_CHARS} caracteres.`),
  }).superRefine((value, ctx) => {
    const issue = (path: string, message: string) => ctx.addIssue({ code: z.ZodIssueCode.custom, path: [path], message })
    const datesValid = isCalendarDate(value.starts_on) && isCalendarDate(value.ends_on)
    if (datesValid && value.ends_on < value.starts_on) {
      issue('ends_on', 'A data final deve ser igual ou posterior à inicial.')
    } else if (datesValid && absenceDays(value.starts_on, value.ends_on) > ABSENCE_MAX_DAYS) {
      issue('ends_on', `A ausência pode ter no máximo ${ABSENCE_MAX_DAYS} dias.`)
    }
    if (!canRetroactive && isCalendarDate(value.starts_on) && value.starts_on < today) {
      issue('starts_on', ABSENCE_RETROACTIVE_MESSAGE)
    }
    if (value.period_type === 'full_day') return
    // P5: horário só em ausência de um dia.
    if (datesValid && value.starts_on !== value.ends_on) {
      issue('period_type', 'Horário só em ausência de um dia; período de vários dias é sempre dia inteiro.')
      return
    }
    if (!ABSENCE_TIME.test(value.start_time)) {
      issue('start_time', value.start_time ? 'Horário inicial inválido.' : 'Informe o horário inicial.')
      return
    }
    const interval = value.period_type === 'interval'
    if (interval && !ABSENCE_TIME.test(value.end_time)) {
      issue('end_time', value.end_time ? 'Horário final inválido.' : 'Informe o horário final.')
      return
    }
    if (interval && value.end_time <= value.start_time) {
      issue('end_time', 'No intervalo, o horário final deve ser posterior ao inicial.')
      return
    }
    // P1: dentro da jornada do técnico (AbsenceRules::assertWithinWorkday).
    if (!workday) return
    const start = typeof workday.start === 'string' && STORED_TIME.test(workday.start) ? workday.start.slice(0, 5) : null
    const end = typeof workday.end === 'string' && STORED_TIME.test(workday.end) ? workday.end.slice(0, 5) : null
    if (start === null || end === null || start >= end) {
      issue('start_time', 'A jornada do cadastro é inválida: lance a ausência como dia inteiro ou corrija a jornada.')
      return
    }
    const outside = `O horário deve ficar dentro da jornada do técnico (${start}–${end}).`
    if (value.start_time < start || value.start_time >= end) issue('start_time', outside)
    else if (interval && value.end_time > end) issue('end_time', outside)
  })
}

export type ReportFiltersFormValues = z.infer<typeof reportFiltersSchema>
export type EmployeeFormValues = z.infer<typeof employeeFormSchema>
export type ScheduleRuleFormValues = z.infer<typeof scheduleRuleSchema>
