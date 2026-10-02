import { z } from 'zod'

const scheduleWeekdaySchema = z.enum(['mon', 'tue', 'wed', 'thu', 'fri'], {
  error: 'Dia da semana invalido.',
})

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
  ], {
    error: 'Tipo de escala invalido.',
  }),
  effective_from: z.string()
    .min(1, 'Informe a data de vigencia.')
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'Data de vigencia invalida.'),
  weekdays: z.array(scheduleWeekdaySchema).default([]),
}).superRefine((value, ctx) => {
  if (value.rule_type === 'fixed_weekdays' && value.weekdays.length === 0) {
    ctx.addIssue({
      code: z.ZodIssueCode.custom,
      path: ['weekdays'],
      message: 'Selecione ao menos um dia da semana.',
    })
  }
})

export type ReportFiltersFormValues = z.infer<typeof reportFiltersSchema>
export type EmployeeFormValues = z.infer<typeof employeeFormSchema>
export type ScheduleRuleFormValues = z.infer<typeof scheduleRuleSchema>
