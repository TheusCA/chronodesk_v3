import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

// [F3, commit 6] Página de Ausências (RHF + Zod) e exceção de escala sem os
// tipos de ausência (P7). Desenho: docs/DESENHO_PA_ESCALA_AUSENCIA.md, 5.4 e 9.4.

const root = dirname(fileURLToPath(import.meta.url))
const frontendRoot = join(root, '..')
const projectRoot = join(frontendRoot, '..')
const srcRoot = join(frontendRoot, 'src')

const read = (relativePath) => readFileSync(join(frontendRoot, relativePath), 'utf8')
const readProject = (relativePath) => readFileSync(join(projectRoot, relativePath), 'utf8')

const packageJson = JSON.parse(read('package.json'))
const appSource = read('src/App.jsx')
const moduleSource = read('src/pages/ModulePage.jsx')
const navigationSource = read('src/lib/navigation.ts')
const pageSource = read('src/pages/AbsencesPage.jsx')
const operationalSource = read('src/pages/OperationalPages.jsx')
const feedbackSource = read('src/lib/actionFeedback.ts')
const rulesSource = readProject('services/AbsenceRules.php')
const operationalServiceSource = readProject('services/OperationalService.php')
const engineSource = readProject('services/PresenceEngine.php')

assert.equal(packageJson.scripts['qa:absences-form'], 'node scripts/qa-absences-form.mjs', 'Script qa:absences-form deve estar registrado')

// --- Rota: página própria no lugar da tabela genérica -------------------------
assert.match(appSource, /const AbsencesPage = lazyPage\(\(\) => import\('\.\/pages\/AbsencesPage'\), 'AbsencesPage'\)/, 'Ausências deve ser página própria, com lazy loading')
assert.match(appSource, /router\.path === '\/ausencias'\) \{\s*content = <AbsencesPage session=\{session\} notify=\{notify\} \/>/, 'Rota /ausencias deve usar AbsencesPage')
assert.doesNotMatch(moduleSource, /'\/ausencias'/, 'A tabela genérica não deve mais atender /ausencias')
assert.match(navigationSource, /'\/ausencias': \['Ausências', '[^']+'\]/, 'Metadados de /ausencias devem existir')
assert.doesNotMatch(navigationSource.match(/'\/ausencias': \[[^\]]+\]/)[0], /atestado/i, 'Subtítulo do menu sem "atestados"')

// --- Limites iguais aos do servidor -------------------------------------------
const schemas = await import(pathToFileURL(join(srcRoot, 'lib', 'formSchemas.ts')).href)
const { absenceFormSchema, absenceDays, isCalendarDate, ABSENCE_CATEGORIES, ABSENCE_MAX_DAYS, ABSENCE_NOTE_MAX_CHARS, ABSENCE_RETROACTIVE_MESSAGE } = schemas
const phpCategories = [...rulesSource.match(/public const CATEGORIES = \[([\s\S]*?)\];/)[1].matchAll(/'([a-z_]+)' => '([^']+)'/g)].map((match) => [match[1], match[2]])
assert.deepEqual(ABSENCE_CATEGORIES.map((option) => [option.value, option.label]), phpCategories, 'Categorias e rótulos iguais aos de AbsenceRules::CATEGORIES')
assert.equal(ABSENCE_MAX_DAYS, Number(rulesSource.match(/MAX_DAYS = (\d+);/)[1]), 'Máximo de dias igual ao do servidor')
assert.equal(ABSENCE_NOTE_MAX_CHARS, Number(rulesSource.match(/NOTE_MAX_CHARS = (\d+);/)[1]), 'Observação com o mesmo limite do servidor')
assert.match(rulesSource, /PERIOD_TYPES = \['full_day', 'from_time', 'interval'\]/, 'Tipos de período iguais aos do servidor')

const TODAY = '2026-10-08'
const WORKDAY = { start: '08:00:00', end: '17:00' }
const base = { employee_id: '7', starts_on: '2026-10-09', ends_on: '2026-10-09', period_type: 'full_day', start_time: '', end_time: '', category: 'folga', note: '' }

function issues(overrides, context = {}) {
  const schema = absenceFormSchema({ today: TODAY, canRetroactive: false, workday: WORKDAY, ...context })
  const result = schema.safeParse({ ...base, ...overrides })
  return result.success ? [] : result.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`)
}

assert.deepEqual(issues({}), [], 'Dia inteiro válido')
assert.deepEqual(issues({ starts_on: TODAY, ends_on: TODAY }), [], 'Começar hoje não é retroativo')
assert.deepEqual(issues({ employee_id: '' }), ['employee_id: Selecione o técnico.'], 'Técnico obrigatório')
assert.deepEqual(issues({ employee_id: '0' }), ['employee_id: Técnico inválido.'], 'Técnico com id zero')
assert.deepEqual(issues({ category: 'atestado' }), ['category: Selecione a categoria.'], 'Categoria fora da lista (atestado saiu na 012)')
assert.deepEqual(issues({ starts_on: '2026-02-30', ends_on: '2026-03-01' }), ['starts_on: Data inicial inválida.'], 'Data inexistente')
assert.deepEqual([isCalendarDate('2028-02-29'), isCalendarDate('2026-02-29'), isCalendarDate('2026-10-8')], [true, false, false], 'isCalendarDate')

// Fim depois do início e até 366 dias.
assert.deepEqual(issues({ ends_on: '2026-10-08', starts_on: '2026-10-09' }), ['ends_on: A data final deve ser igual ou posterior à inicial.'], 'Fim antes do início')
assert.equal(absenceDays('2026-10-09', '2027-10-09'), 366, 'absenceDays conta o primeiro e o último dia')
assert.deepEqual(issues({ ends_on: '2027-10-09' }), [], '366 dias')
assert.deepEqual(issues({ ends_on: '2027-10-10' }), ['ends_on: A ausência pode ter no máximo 366 dias.'], '367 dias')
assert.deepEqual(issues({ starts_on: '2027-03-01', ends_on: '2028-02-29' }), [], '366 dias atravessando 29/02/2028')
assert.deepEqual(issues({ starts_on: '2027-03-01', ends_on: '2028-03-01' }), ['ends_on: A ausência pode ter no máximo 366 dias.'], '367 dias atravessando 29/02/2028')

// Retroativa só com permissão.
assert.deepEqual(issues({ starts_on: '2026-10-07', ends_on: '2026-10-07' }), [`starts_on: ${ABSENCE_RETROACTIVE_MESSAGE}`], 'Gestor: início anterior a hoje')
assert.deepEqual(issues({ starts_on: '2026-10-07', ends_on: '2026-10-07' }, { canRetroactive: true }), [], 'Liderança e admin: retroativa permitida')

// P5: horário só em um dia.
assert.deepEqual(issues({ ends_on: '2026-10-10', period_type: 'from_time', start_time: '14:00' }), ['period_type: Horário só em ausência de um dia; período de vários dias é sempre dia inteiro.'], 'Horário em vários dias')
assert.deepEqual(issues({ period_type: 'from_time' }), ['start_time: Informe o horário inicial.'], '"A partir de" sem horário')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '25:00' }), ['start_time: Horário inicial inválido.'], 'Horário inválido')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '14:00' }), [], '"A partir de 14:00"')
assert.deepEqual(issues({ period_type: 'interval', start_time: '10:00' }), ['end_time: Informe o horário final.'], 'Intervalo sem fim')
assert.deepEqual(issues({ period_type: 'interval', start_time: '12:00', end_time: '12:00' }), ['end_time: No intervalo, o horário final deve ser posterior ao inicial.'], 'Intervalo com fim igual ao início')
assert.deepEqual(issues({ period_type: 'interval', start_time: '12:00', end_time: '10:00' }), ['end_time: No intervalo, o horário final deve ser posterior ao inicial.'], 'Intervalo invertido')
assert.deepEqual(issues({ period_type: 'interval', start_time: '10:00', end_time: '12:00' }), [], 'Intervalo 10:00–12:00')

// P1: dentro da jornada (início em [entrada, saída); fim do intervalo até a saída).
const outside = 'O horário deve ficar dentro da jornada do técnico (08:00–17:00).'
assert.deepEqual(issues({ period_type: 'from_time', start_time: '07:59' }), [`start_time: ${outside}`], 'Antes da entrada')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '08:00' }), [], 'Na entrada')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '16:59' }), [], 'Um minuto antes da saída')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '17:00' }), [`start_time: ${outside}`], 'Na saída')
assert.deepEqual(issues({ period_type: 'interval', start_time: '16:00', end_time: '17:00' }), [], 'Intervalo até a saída')
assert.deepEqual(issues({ period_type: 'interval', start_time: '16:00', end_time: '17:01' }), [`end_time: ${outside}`], 'Intervalo passando da saída')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '14:00' }, { workday: { start: '17:00', end: '08:00' } }), ['start_time: A jornada do cadastro é inválida: lance a ausência como dia inteiro ou corrija a jornada.'], 'Jornada inválida no cadastro')
assert.deepEqual(issues({}, { workday: { start: null, end: null } }), [], 'Dia inteiro não depende da jornada')
assert.deepEqual(issues({ period_type: 'from_time', start_time: '06:00' }, { workday: null }), [], 'Sem a jornada na tela, quem confere é o servidor')

// Observação: até 500 caracteres como o servidor conta (pontos de código), aparada.
assert.deepEqual(issues({ note: 'a'.repeat(500) }), [], '500 caracteres')
assert.deepEqual(issues({ note: 'a'.repeat(501) }), ['note: A observação pode ter no máximo 500 caracteres.'], '501 caracteres')
assert.deepEqual(issues({ note: 'ç'.repeat(500) }), [], '500 caracteres acentuados')
assert.deepEqual(issues({ note: '🙂'.repeat(500) }), [], '500 emojis (1000 unidades UTF-16) contam como 500')
assert.deepEqual(issues({ note: ` ${'a'.repeat(500)} ` }), [], 'Espaços nas pontas não contam')
assert.equal(absenceFormSchema({ today: TODAY, canRetroactive: false }).parse({ ...base, note: '  texto  ' }).note, 'texto', 'Observação aparada')

// --- Página: RHF + Zod, aviso junto do campo, P9, sem confirm ------------------
assert.match(pageSource, /import \{ useForm \} from 'react-hook-form'/, 'Página com React Hook Form')
assert.match(pageSource, /useForm\(\{ defaultValues: emptyAbsence\(\) \}\)/, 'Formulário com valores iniciais')
assert.match(pageSource, /onSubmit=\{handleSubmit\(saveAbsence\)\}/, 'Submit passa por handleSubmit')
assert.match(pageSource, /absenceFormSchema\(\{ today, canRetroactive, workday \}\)\.safeParse\(formValues\)/, 'Zod manual com a jornada do técnico, hoje e a permissão')
assert.match(pageSource, /setError\(field, \{ type: 'zod'/, 'Erros do Zod em formState.errors')
for (const field of ['employee_id', 'starts_on', 'ends_on', 'period_type', 'start_time', 'end_time', 'category', 'note']) {
  assert.match(pageSource, new RegExp(`<FieldError error=\\{errors\\.${field}\\} \\/>`), `Erro junto do campo: ${field}`)
}
assert.match(pageSource, /hasPermission\(session, 'ausencias\.manage'\)/, 'Formulário e ações pela permissão ausencias.manage')
assert.match(pageSource, /hasPermission\(session, 'ausencias\.retroativa'\)/, 'Retroativa pela permissão ausencias.retroativa')
assert.match(pageSource, /hasPermission\(session, 'escalas\.lideranca'\)/, 'Ausência da Liderança só com a permissão da L9')
assert.match(pageSource, /min=\{canRetroactive \? undefined : today\}/, 'Data anterior a hoje desabilitada para quem não tem a permissão')
assert.match(pageSource, /title="Lançamento retroativo \(auditado\)"/, 'Marca de lançamento retroativo')
assert.match(pageSource, /disabled=\{!singleDay\} type="radio" value="from_time"/, 'P5: "A partir de" só com um dia')
assert.match(pageSource, /disabled=\{!singleDay\} type="radio" value="interval"/, 'P5: intervalo só com um dia')
assert.match(pageSource, /setValue\('period_type', 'full_day'/, 'P5: vários dias volta para dia inteiro')

// Aviso fixo junto do campo de observação, ligado por aria-describedby.
assert.match(pageSource, /<textarea aria-describedby=\{NOTE_WARNING_ID\}[^>]*\{\.\.\.register\('note'/, 'Observação descrita pelo aviso')
assert.match(pageSource, /\{\.\.\.register\('note'[^\n]*\/>\s*<span[^>]*>\s*<span[^>]*id=\{NOTE_WARNING_ID\}>⚠ Não registre informação médica ou de saúde neste campo\.<\/span>/, 'Aviso logo abaixo do campo, sempre visível')

// P9: categoria e observação só para a gestão e para o próprio técnico.
assert.match(pageSource, /const seesAllDetails = isManagerRole\(session\.role\)/, 'Filtros de equipe e técnico pela gestão')
assert.match(pageSource, /const canSeeDetails = \(item\) => canSeeAbsenceDetails\(session, item\)/, 'P9 da tela pela função testada abaixo')

// P9, segunda camada (commit 7): comportamento por perfil, inclusive linha que
// não é de quem consulta chegando a técnico ou somente leitura.
const { canSeeAbsenceDetails } = await import(pathToFileURL(join(srcRoot, 'lib', 'roles.ts')).href)
const sessionOf = (role, funcionarioId = 0) => ({ role, ci: { funcionario_id: funcionarioId } })
for (const role of ['gestor', 'lideranca', 'admin']) {
  assert.equal(canSeeAbsenceDetails(sessionOf(role), { employee_id: 12 }), true, `${role} vê categoria e observação de qualquer linha`)
}
for (const role of ['tecnico', 'somente_leitura']) {
  assert.equal(canSeeAbsenceDetails(sessionOf(role, 11), { employee_id: 11 }), true, `${role}: a própria linha`)
  assert.equal(canSeeAbsenceDetails(sessionOf(role, 11), { employee_id: '11' }), true, `${role}: a própria linha com id em texto`)
  assert.equal(canSeeAbsenceDetails(sessionOf(role, 11), { employee_id: 12 }), false, `${role}: linha de outro colaborador`)
  assert.equal(canSeeAbsenceDetails(sessionOf(role, 0), { employee_id: 0 }), false, `${role} sem colaborador na sessão: nada`)
  assert.equal(canSeeAbsenceDetails(sessionOf(role, 11), {}), false, `${role}: linha sem colaborador`)
  assert.equal(canSeeAbsenceDetails({ role }, { employee_id: 11 }), false, `${role} sem bloco ci na sessão`)
}
assert.equal(canSeeAbsenceDetails(null, { employee_id: 11 }), false, 'Sem sessão: nada')
assert.equal(canSeeAbsenceDetails(sessionOf('perfil_desconhecido', 11), { employee_id: 12 }), false, 'Perfil desconhecido: só as próprias')
const categoryCell = pageSource.match(/function categoryCell\(item\) \{\s*if \(!canSeeDetails\(item\)\) return <span>Ausência<\/span>[\s\S]*?\n {2}\}/)?.[0] || ''
assert.equal((pageSource.match(/\{categoryCell\(item\)\}/g) || []).length, 2, 'Tabela e cartões usam o mesmo ramo de P9')
assert.match(categoryCell, /CATEGORY_LABELS\[item\.category\]/, 'Categoria dentro do ramo visível')
assert.match(categoryCell, /item\.note && <small>\{item\.note\}<\/small>/, 'Observação dentro do ramo visível')
// Três usos: os dois do ramo visível e o preenchimento da edição (só a gestão edita).
assert.equal((pageSource.match(/item\.note/g) || []).length, 3, 'Observação não aparece fora do ramo visível nem fora da edição')
assert.match(pageSource, /note: item\.note \|\| '',\s*\}\)\s*setFormOpen\(true\)/, 'Observação na edição só para preencher o formulário')
assert.equal((pageSource.match(/CATEGORY_LABELS\[/g) || []).length, 1, 'Categoria não aparece fora do ramo visível')
assert.match(pageSource, /\{seesAllDetails && \(\s*<>\s*<label className="label">Equipe/, 'Filtros de equipe e técnico só para a gestão')

// Ações: cancelar com confirmação na linha (sem window.confirm), mensagem do servidor junto do formulário.
assert.doesNotMatch(pageSource, /\bconfirm\(|\balert\(|\bprompt\(/, 'Sem confirm/alert/prompt do navegador')
assert.match(pageSource, /if \(confirmCancelId === item\.id\) \{[\s\S]*Cancelar esta ausência\?[\s\S]*onClick=\{\(\) => cancelAbsence\(item\)\}/, 'Cancelamento confirmado na própria linha')
assert.match(pageSource, /post\('portal\/absences\.php', buildAbsencePayload\(data, editing\?\.id\)\)/, 'Lançar e editar no endpoint de ausências')
assert.match(pageSource, /action: editingId \? 'update' : 'create'/, 'Ação create/update')
assert.match(pageSource, /post\('portal\/absences\.php', \{ action: 'cancel', id: item\.id \}\)/, 'Cancelar pelo id')
assert.match(pageSource, /onError: \(error\) => setFormError\(/, 'Recusa do servidor também junto do formulário')
assert.match(pageSource, /if \(item\.starts_on <= today && !canRetroactive\) return/, 'Ausência já iniciada: ações só com a permissão de retroativa')
for (const key of ['absenceCreated', 'absenceUpdated', 'absenceCancelled']) {
  assert.match(feedbackSource, new RegExp(`${key}: '`), `ACTION_FEEDBACK.${key} deve existir`)
  assert.match(pageSource, new RegExp(`ACTION_FEEDBACK\\.${key}`), `Página usa ACTION_FEEDBACK.${key}`)
}

// --- P7: exceção de escala só com trabalho ------------------------------------
const exceptionOptions = [...(operationalSource.match(/const EXCEPTION_TYPE_OPTIONS = \[([\s\S]*?)\n\]/)?.[1] || '').matchAll(/value: '([a-z_]+)'/g)].map((match) => match[1])
const serverWorkTypes = [...(operationalServiceSource.match(/if \(!in_array\(\$type, \[([^\]]+)\], true\)\)/)?.[1] || '').matchAll(/'([a-z_]+)'/g)].map((match) => match[1])
assert.deepEqual(exceptionOptions, ['remote', 'onsite', 'training', 'oncall'], 'Exceção: remoto, presencial, treinamento e plantão')
assert.deepEqual(exceptionOptions, serverWorkTypes, 'Tipos da tela iguais aos aceitos por saveScheduleException')
const absenceExceptionTypes = [...engineSource.match(/ABSENCE_EXCEPTIONS = \[([^\]]+)\]/)[1].matchAll(/'([a-z_]+)'/g)].map((match) => match[1])
const exceptionForm = operationalSource.match(/<form className="card space-y-4" onSubmit=\{saveException\}>[\s\S]*?<\/form>/)?.[0] || ''
assert.match(exceptionForm, /EXCEPTION_TYPE_OPTIONS/, 'Formulário de exceção usa a lista de tipos de trabalho')
assert.equal(absenceExceptionTypes.length, 4, 'PresenceEngine::ABSENCE_EXCEPTIONS lida')
for (const type of absenceExceptionTypes) {
  assert.equal(exceptionForm.includes(`value="${type}"`), false, `Tipo de ausência fora da exceção: ${type}`)
  assert.equal(exceptionOptions.includes(type), false, `Tipo de ausência fora das opções: ${type}`)
}
assert.match(operationalSource, /EXCEPTION_TYPE_OPTIONS\.filter\(\(option\) => !\(exceptionIsLeadership && option\.value === 'remote'\)\)/, 'Liderança continua sem exceção remota')
assert.match(operationalSource, /\(error\) => setExceptionError\(/, 'Recusa do servidor (400 do P7) guardada para a tela')
assert.match(operationalSource, /\{exceptionError && <InlineAlert tone="danger" title="Exceção não salva">\{exceptionError\}<\/InlineAlert>\}/, 'Mensagem do servidor junto do formulário de exceção')
assert.match(operationalSource, /return runAction\(\{\s*action,\s*refresh,\s*notify,\s*successMessage,\s*onSuccess,\s*onError,\s*\}\)/, 'submit repassa onError ao runAction')

console.log('Absences form RHF/Zod QA OK')
