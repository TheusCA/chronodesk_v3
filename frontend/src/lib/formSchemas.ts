import { z } from 'zod'

const scheduleWeekdaySchema = z.enum(['mon', 'tue', 'wed', 'thu', 'fri'], {
  error: 'Dia da semana invalido.',
})

export const reportFiltersSchema = z.object({
  competency: z.string().min(1, 'Informe a competencia.').regex(/^\d{4}-\d{2}$/, 'Competencia invalida.'),
  team: z.enum(['', 'n1', 'n2']).default(''),
  employee_id: z.string().default(''),
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
export type ScheduleRuleFormValues = z.infer<typeof scheduleRuleSchema>
