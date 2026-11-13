// [R2] Primitivos do redesenho (docs/design/PLANO_LOTES_R.md): cada componente
// desenhado de verdade (JSX transpilado pelo TypeScript do projeto e
// react-dom/server) e o CSS gerado pelo Tailwind com a configuração do projeto.
// Confere os estados e a acessibilidade de cada um e o alvo de toque de 40 px
// no celular (a regra fora de @media vale em 390 px).
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { registerHooks } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import ts from 'typescript'

const frontendRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const srcRoot = join(frontendRoot, 'src')
const read = (path) => readFileSync(join(frontendRoot, path), 'utf8')
const srcUrl = pathToFileURL(srcRoot).href + '/'
registerHooks({
  resolve(specifier, context, nextResolve) {
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && !/\.[cm]?[jt]sx?$/u.test(specifier) && context.parentURL?.startsWith(srcUrl)) {
      for (const extension of ['.ts', '.tsx', '.js', '.jsx']) {
        const candidate = new URL(specifier + extension, context.parentURL)
        if (existsSync(fileURLToPath(candidate))) return nextResolve(candidate.href, context)
      }
    }
    return nextResolve(specifier, context)
  },
  load(url, context, nextLoad) {
    const path = url.split('?')[0]
    if (path.startsWith(srcUrl) && /\.(jsx|tsx|ts)$/u.test(path)) {
      const output = ts.transpileModule(readFileSync(fileURLToPath(path), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 }, fileName: fileURLToPath(path) }).outputText
      return { format: 'module', source: output, shortCircuit: true }
    }
    return nextLoad(url, context)
  },
})
const { createElement: h } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')
const ui = async (name) => import(pathToFileURL(join(srcRoot, 'components', 'ui', `${name}.jsx`)).href)
const render = (element) => renderToStaticMarkup(element)
const text = (markup) => markup.replace(/<[^>]+>/g, '')

// CSS gerado com a configuração do projeto (como no build).
const { default: postcss } = await import('postcss')
const { default: tailwindcss } = await import('tailwindcss')
const { default: tailwindConfig } = await import(pathToFileURL(join(frontendRoot, 'tailwind.config.js')).href)
const compiled = (await postcss([tailwindcss({ ...tailwindConfig, content: tailwindConfig.content.map((glob) => join(frontendRoot, glob).replaceAll('\\', '/')) })]).process(read('src/index.css'), { from: join(srcRoot, 'index.css') })).css
// Regras fora de qualquer @media: o que vale em 390 px.
const outsideMedia = (() => {
  let out = ''
  let index = 0
  while (index < compiled.length) {
    const media = compiled.indexOf('@media', index)
    if (media < 0) { out += compiled.slice(index); break }
    out += compiled.slice(index, media)
    let depth = 0
    let cursor = compiled.indexOf('{', media)
    for (; cursor < compiled.length; cursor++) {
      if (compiled[cursor] === '{') depth++
      if (compiled[cursor] === '}' && --depth === 0) break
    }
    index = cursor + 1
  }
  return out
})()
// O Tailwind indenta as regras que saem de @layer: o seletor pode vir depois de espaços.
const rulesOf = (css, className) => [...css.matchAll(new RegExp(`(?:^|[\\n,])\\s*\\.${className.replaceAll('-', '\\-')}(?![\\w-])[^{,]*\\{([^}]*)\\}`, 'g'))].map((match) => match[1])
const pxOf = (value) => Number.parseFloat(value) * (value.endsWith('rem') ? 16 : 1)
const mobileMinHeight = (className) => {
  const heights = rulesOf(outsideMedia, className).flatMap((rule) => [...rule.matchAll(/(?:^|[;\s])min-height:\s*([\d.]+(?:rem|px))/g)].map((match) => pxOf(match[1])))
  return heights.length ? heights.at(-1) : null
}

// ---------------------------------------------------------------------------
// 1. Alvo de toque: 40 px no celular em todo controle das classes do portal.
// ---------------------------------------------------------------------------
// .btn sozinha não sai no CSS (ninguém a usa direto); as variantes a trazem.
for (const className of ['btn-primary', 'btn-secondary', 'btn-ghost', 'btn-danger', 'table-action', 'tab-button', 'segmented-option']) {
  const height = mobileMinHeight(className)
  assert.ok(height !== null && height >= 40, `.${className}: altura mínima de 40 px no celular (achado: ${height ?? 'nenhuma'})`)
}
// O compacto só encolhe de 640 px para cima.
assert.ok((mobileMinHeight('btn-sm') ?? 40) >= 40, '.btn-sm não encolhe no celular')
assert.match(compiled, /@media \(min-width: 640px\) \{\s*\.btn-sm \{\s*min-height: 2rem;/, 'compacto com 32 px de 640 px para cima')
assert.match(read('src/index.css'), /\.btn-sm \{\s*@apply [^;]*\bsm:min-h-8\b/, '.btn-sm usa sm:min-h-8 (não min-h-8)')
for (const [file, pattern, label] of [
  ['src/pages/AdminPage.jsx', /min-h-9|min-h-8(?!\S)/, 'Aprovar e Rejeitar sem altura menor que 40 px'],
  ['src/pages/DashboardPage.jsx', /className="[^"]*min-h-9/, 'Derrubar pausa (Visão operacional) sem 36 px'],
  ['src/pages/PausasPage.jsx', /className="[^"]*min-h-9/, 'Derrubar pausa (Pausas) sem 36 px'],
]) {
  assert.doesNotMatch(read(file), pattern, label)
}

// ---------------------------------------------------------------------------
// 2. Button.
// ---------------------------------------------------------------------------
{
  const { Button } = await ui('Button')
  const plain = render(h(Button, null, 'Salvar'))
  assert.match(plain, /^<button class="btn-secondary" type="button">Salvar<\/button>$/, 'Button: secundário e type="button" por padrão')
  assert.match(render(h(Button, { variant: 'primary' }, 'Salvar')), /^<button class="btn-primary" type="button">/, 'Button: principal')
  assert.match(render(h(Button, { variant: 'quiet' }, 'Fechar')), /^<button class="btn-ghost" type="button">/, 'Button: discreto')
  assert.match(render(h(Button, { variant: 'danger' }, 'Remover')), /^<button class="btn-danger" type="button">/, 'Button: perigo')
  assert.match(render(h(Button, { variant: 'roxo' }, 'X')), /^<button class="btn-secondary"/, 'Button: variante desconhecida cai no secundário')
  assert.match(render(h(Button, { size: 'sm', variant: 'primary' }, 'Aprovar')), /^<button class="btn-primary btn-sm" type="button">/, 'Button: compacto')
  assert.match(render(h(Button, { type: 'submit', variant: 'primary' }, 'Enviar')), /type="submit"/, 'Button: submit só quando pedido')
  const busy = render(h(Button, { busy: true, busyLabel: 'Salvando...', variant: 'primary' }, 'Salvar'))
  assert.match(busy, /aria-busy="true"/, 'Button: ocupado com aria-busy')
  assert.match(busy, /disabled=""/, 'Button: ocupado fica desabilitado')
  assert.equal(text(busy), 'Salvando...', 'Button: ocupado mostra o rótulo de envio')
  assert.doesNotMatch(plain, /aria-busy/, 'Button: sem aria-busy quando livre')
  const withIcon = render(h(Button, { icon: 'pause' }, 'Iniciar pausa'))
  assert.match(withIcon, /<svg[^>]*aria-hidden="true"/, 'Button: ícone decorativo, fora do nome acessível')
  assert.equal(text(withIcon), 'Iniciar pausa', 'Button: o nome é o rótulo')
  assert.match(render(h(Button, { 'aria-label': 'Fechar', onClick: () => {} }, 'x')), /aria-label="Fechar"/, 'Button: repassa atributos')
}

// ---------------------------------------------------------------------------
// 3. Tag: nunca só a cor.
// ---------------------------------------------------------------------------
{
  const { Tag } = await ui('Tag')
  const toneClass = (tone) => render(h(Tag, { tone }, 'x')).match(/^<span class="status-badge ([^"]+)">/)?.[1]
  const onsite = render(h(Tag, { tone: 'presence-onsite', icon: 'building' }, 'Presencial'))
  assert.match(onsite, /^<span class="status-badge presence-onsite"><svg/, 'Tag: situação com a classe da legenda e o ícone')
  assert.equal(text(onsite), 'Presencial', 'Tag: o rótulo aparece')
  assert.equal(text(render(h(Tag, { tone: 'success' }, 'Aprovado'))), 'Aprovado', 'Tag: rótulo sem ícone')
  for (const [tone, expected] of [['success', 'status-success'], ['info', 'status-info'], ['warning', 'status-warning'], ['danger', 'status-danger'], ['neutral', 'status-neutral'], ['presence-lunch', 'presence-lunch'], ['roxo', 'status-neutral'], ['presence-x y', 'status-neutral'], [undefined, 'status-neutral']]) {
    assert.equal(toneClass(tone), expected, `Tag: tom ${tone} vira ${expected}`)
  }
  assert.match(render(h(Tag, { tone: 'info', title: 'Detalhe' }, 'Info')), /title="Detalhe"/, 'Tag: title opcional')
  for (const tone of ['status-success', 'status-info', 'status-warning', 'status-danger', 'status-neutral']) {
    const rule = rulesOf(compiled, tone).join(' ')
    for (const property of ['border-color', 'background-color', 'color']) assert.match(rule, new RegExp(`(?:^|[;\\s])${property}: rgb\\(var\\(--`), `.${tone}: ${property} por token`)
  }
}

// ---------------------------------------------------------------------------
// 4. Segmented.
// ---------------------------------------------------------------------------
{
  const { Segmented } = await ui('Segmented')
  const options = [{ value: 'todos', label: 'Todos', count: 16 }, { value: 'pausa', label: 'Em pausa', count: 2 }, { value: 'sem', label: 'Sem contagem' }]
  const markup = render(h(Segmented, { label: 'Filtrar técnicos', options, value: 'pausa', onChange: () => {} }))
  assert.match(markup, /^<div aria-label="Filtrar técnicos" class="segmented" role="group">/, 'Segmented: grupo com nome')
  const buttons = markup.match(/<button[^>]*>[\s\S]*?<\/button>/g)
  assert.equal(buttons.length, 3, 'Segmented: um botão por opção')
  assert.deepEqual(buttons.map((button) => button.match(/aria-pressed="(true|false)"/)?.[1]), ['false', 'true', 'false'], 'Segmented: só a escolhida com aria-pressed="true"')
  assert.ok(buttons.every((button) => /type="button"/.test(button)), 'Segmented: botões não enviam formulário')
  assert.deepEqual(buttons.map(text), ['Todos16', 'Em pausa2', 'Sem contagem'], 'Segmented: rótulo e contagem (sem contagem, só o rótulo)')
  assert.match(buttons[0], /<span class="segmented-count">16<\/span>/, 'Segmented: contagem com classe própria')
}

console.log('Primitivos QA OK')
