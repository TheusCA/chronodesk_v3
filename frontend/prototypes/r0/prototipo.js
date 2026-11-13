// R0 do redesenho: comportamento do prototipo isolado. Sem rede, sem
// armazenamento, sem dado real. A casca (menu, topo, barra do celular) sai
// de constantes deste arquivo; nenhum texto vem de fora.
//
// URL: ?direcao=a|b&tema=claro|escuro&estado=ativa&painel=editar&congelar=1
// congelar=1 para o relogio em 10:42:05 (capturas repetiveis).

const ICONS = {
  dashboard: ['M3 3h7v7H3z', 'M14 3h7v4h-7z', 'M14 11h7v10h-7z', 'M3 14h7v7H3z'],
  pause: ['M7 5v14', 'M17 5v14'],
  shield: ['M12 3l8 3v5c0 5-3.4 8.7-8 10-4.6-1.3-8-5-8-10V6z', 'M9 12l2 2 4-4'],
  chart: ['M4 19V9', 'M10 19V5', 'M16 19v-7', 'M22 19H2'],
  calendar: ['M6 2v4', 'M18 2v4', 'M3 9h18', 'M5 4h14a2 2 0 012 2v14H3V6a2 2 0 012-2z'],
  users: ['M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2', 'M9 11a4 4 0 100-8 4 4 0 000 8z', 'M22 21v-2a4 4 0 00-3-3.87', 'M16 3.13a4 4 0 010 7.75'],
  clock: ['M12 22a10 10 0 100-20 10 10 0 000 20z', 'M12 6v6l4 2'],
  building: ['M3 21h18', 'M6 21V3h12v18', 'M9 7h2', 'M13 7h2', 'M9 11h2', 'M13 11h2'],
  userMinus: ['M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2', 'M8.5 11a4 4 0 100-8 4 4 0 000 8z', 'M18 8h6'],
  home: ['M3 11l9-8 9 8', 'M5 10v11h14V10', 'M10 21v-6h4v6'],
  help: ['M12 22a10 10 0 100-20 10 10 0 000 20z', 'M9.1 9a3 3 0 015.8 1c0 2-3 3-3 3', 'M12 17h.01'],
  timer: ['M10 2h4', 'M12 14l3-3', 'M12 22a8 8 0 100-16 8 8 0 000 16z'],
  utensils: ['M5 2v6a2 2 0 004 0V2', 'M7 10v12', 'M17 22V2c-2.4 1.6-3.5 4.6-3.5 9H17'],
  edit: ['M12 20h9', 'M16.5 3.5a2.1 2.1 0 013 3L8 18l-4 1 1-4z'],
  bell: ['M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
  phone: ['M22 16.9v3a2 2 0 01-2.2 2A19.8 19.8 0 012 4.2 2 2 0 014 2h3a2 2 0 012 1.7l.7 3a2 2 0 01-.6 1.9L7.8 9.9a16 16 0 006.3 6.3l1.3-1.3a2 2 0 011.9-.6l3 .7a2 2 0 011.7 1.9z'],
  radio: ['M5.6 18.4a9 9 0 010-12.8', 'M18.4 5.6a9 9 0 010 12.8', 'M8.5 15.5a5 5 0 010-7', 'M15.5 8.5a5 5 0 010 7', 'M12 13a1 1 0 100-2 1 1 0 000 2z'],
  alert: ['M10.3 3.6L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.6a2 2 0 00-3.4 0z', 'M12 9v4', 'M12 17h.01'],
  book: ['M4 19.5A2.5 2.5 0 016.5 17H20V3H6.5A2.5 2.5 0 004 5.5z', 'M4 5.5v14'],
  file: ['M14 2H6a2 2 0 00-2 2v16h16V8z', 'M14 2v6h6', 'M8 13h8', 'M8 17h8'],
  settings: ['M12 15.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7z', 'M4 12h2', 'M18 12h2', 'M12 4v2', 'M12 18v2'],
  plug: ['M12 22v-5', 'M9 8V2', 'M15 8V2', 'M18 8v3a6 6 0 01-12 0V8z'],
  logout: ['M10 17l5-5-5-5', 'M15 12H3', 'M21 19V5a2 2 0 00-2-2h-6'],
  menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
  close: ['M6 6l12 12', 'M18 6L6 18'],
  sun: ['M12 17a5 5 0 100-10 5 5 0 000 10z', 'M12 1v2', 'M12 21v2', 'M4.2 4.2l1.4 1.4', 'M18.4 18.4l1.4 1.4', 'M1 12h2', 'M21 12h2', 'M4.2 19.8l1.4-1.4', 'M18.4 5.6l1.4-1.4'],
  chevron: ['M9 6l6 6-6 6'],
  search: ['M11 19a8 8 0 100-16 8 8 0 000 16z', 'M21 21l-4.3-4.3'],
}

const NAV = [
  ['Operação', [['visao-operacional', 'Visão operacional', 'dashboard'], ['', 'Calendário', 'calendar'], ['', 'Técnicos', 'users'], ['', 'Mapa de PA', 'building']]],
  ['Pausas e aprovações', [['pausas', 'Pausas', 'pause'], ['', 'Métricas', 'chart'], ['funcionarios', 'Aprovações e admin', 'shield', '3']]],
  ['Escalas', [['', 'Escala presencial', 'building'], ['', 'Escalas de sábado', 'clock'], ['', 'Ausências', 'userMinus'], ['', 'Plantonistas', 'phone'], ['', 'Sobreavisos', 'radio']]],
  ['Fluxos', [['', 'Horas extras', 'timer'], ['', 'Correção de ponto', 'edit'], ['', 'Avisos', 'bell'], ['', 'Chamados críticos', 'alert']]],
  ['Plataforma', [['', 'Documentação', 'book'], ['', 'Relatórios', 'file'], ['', 'Configurações', 'settings'], ['', 'Integrações', 'plug']]],
]
const TABBAR = [['visao-operacional', 'Agora', 'dashboard'], ['pausas', 'Pausas', 'pause'], ['', 'Escala', 'calendar'], ['', 'Menu', 'menu']]

const params = new URLSearchParams(window.location.search)
const root = document.documentElement
const direction = params.get('direcao') === 'b' ? 'b' : 'a'
const theme = ['claro', 'escuro'].includes(params.get('tema')) ? params.get('tema') : (direction === 'b' ? 'escuro' : 'claro')
root.dataset.direcao = direction
root.dataset.tema = theme
if (params.get('estado') === 'ativa') root.dataset.estado = 'ativa'
if (params.get('painel') === 'editar') root.dataset.painel = 'editar'

const icon = (name, extra = '') => `<svg class="icon ${extra}" viewBox="0 0 24 24" aria-hidden="true">${(ICONS[name] || ICONS.file).map((d) => `<path d="${d}"/>`).join('')}</svg>`
const keep = (page) => page ? `${page}.html?direcao=${direction}&tema=${theme}` : '#'
const current = document.body.dataset.tela

function renderShell() {
  const nav = NAV.map(([group, items]) => `<p class="side__group">${group}</p>${items.map(([page, label, glyph, count]) => (
    `<a class="side__link" href="${keep(page)}"${page === current ? ' aria-current="page"' : ''}>${icon(glyph)}<span>${label}</span>${count ? `<span class="side__count" aria-label="${count} pendentes">${count}</span>` : ''}</a>`
  )).join('')}`).join('')
  document.querySelector('[data-side]').insertAdjacentHTML('afterbegin', `
    <div class="side__brand"><span class="side__mark" aria-hidden="true">SDK</span>Portal SDK</div>
    <nav class="side__nav" aria-label="Módulos do portal">${nav}</nav>
    <div class="side__user"><div><p><strong>${document.body.dataset.usuario}</strong></p><p class="muted">${document.body.dataset.perfil}</p></div>
      <a class="btn btn--quiet btn--icon push-right" href="#" aria-label="Sair">${icon('logout')}</a></div>`)
  document.querySelector('[data-top]').insertAdjacentHTML('afterbegin', `
    <button class="btn btn--quiet btn--icon top__menu" type="button" aria-label="Abrir menu">${icon('menu')}</button>
    <div class="top__title"><h1 tabindex="-1">${document.body.dataset.titulo}</h1></div>
    <p class="top__live"><span>Ao vivo,</span> <time class="num" data-relogio>10:42</time></p>
    <button class="btn btn--quiet btn--icon" type="button" data-tema-alternar aria-label="Mudar para o tema ${theme === 'claro' ? 'escuro' : 'claro'}">${icon('sun')}</button>
    <button class="btn btn--quiet btn--icon" type="button" aria-label="Notificações">${icon('bell')}</button>`)
  document.querySelector('[data-tabbar]').insertAdjacentHTML('afterbegin', TABBAR.map(([page, label, glyph]) => (
    `<a href="${keep(page)}"${page === current ? ' aria-current="page"' : ''}>${icon(glyph)}<span>${label}</span></a>`
  )).join(''))
  // Largura de cada parte da barra pelo numero (CSSOM: a CSP style-src 'self' barra atributo style).
  for (const part of document.querySelectorAll('.bar [data-n]')) part.style.flexGrow = part.dataset.n
  for (const glyph of document.querySelectorAll('[data-icone]')) glyph.insertAdjacentHTML('afterbegin', icon(glyph.dataset.icone, glyph.dataset.iconeClasse || ''))
}

// Relogio do servidor simulado. Os timers contam a partir de data-desde (HH:MM:SS).
const frozen = params.get('congelar') === '1'
const start = Date.now()
const base = 10 * 3600 + 42 * 60 + 5
const seconds = (text) => text.split(':').reduce((total, part) => total * 60 + Number(part), 0)
const pad = (value) => String(value).padStart(2, '0')
const clockText = (total, withSeconds = false) => `${pad(Math.floor(total / 3600) % 24)}:${pad(Math.floor(total / 60) % 60)}${withSeconds ? `:${pad(total % 60)}` : ''}`
const duration = (total) => `${Math.floor(total / 60)}:${pad(total % 60)}`

function tick() {
  const now = base + (frozen ? 0 : Math.floor((Date.now() - start) / 1000))
  for (const node of document.querySelectorAll('[data-relogio]')) node.textContent = clockText(now)
  for (const node of document.querySelectorAll('[data-relogio-s]')) node.textContent = clockText(now, true)
  for (const node of document.querySelectorAll('[data-desde]')) {
    const elapsed = Math.max(0, now - seconds(node.dataset.desde))
    const limit = Number(node.dataset.limite || 0)
    node.textContent = duration(elapsed)
    const over = limit > 0 && elapsed > limit
    node.classList.toggle('late', over)
    const note = node.parentElement.querySelector('[data-passou]')
    if (note) note.textContent = over ? `passou ${duration(elapsed - limit)}` : (limit ? `faltam ${duration(limit - elapsed)}` : 'sem limite')
    const meter = node.closest('[data-medidor]')?.querySelector('.meter span')
    if (meter && limit) meter.style.width = `${Math.min(100, (elapsed / limit) * 100)}%`
  }
}

function wire() {
  document.querySelector('[data-tema-alternar]')?.addEventListener('click', () => {
    params.set('tema', theme === 'claro' ? 'escuro' : 'claro')
    window.location.search = params.toString()
  })
  // Filtro da equipe (Pausas): mostra as linhas do grupo escolhido.
  for (const button of document.querySelectorAll('[data-filtro]')) {
    button.addEventListener('click', () => {
      for (const other of document.querySelectorAll('[data-filtro]')) other.setAttribute('aria-pressed', String(other === button))
      const wanted = button.dataset.filtro
      for (const row of document.querySelectorAll('[data-grupo]')) row.hidden = wanted !== 'todos' && row.dataset.grupo !== wanted
    })
  }
  // Escolha da pausa: o botao diz o que vai acontecer; reuniao pede o contexto.
  const go = document.querySelector('[data-iniciar]')
  for (const option of document.querySelectorAll('input[name="motivo"]')) {
    option.addEventListener('change', () => {
      go.disabled = false
      go.textContent = option.value === 'Reunião' ? 'Pedir aprovação da reunião' : `Iniciar pausa de ${option.value.toLowerCase()}`
      document.querySelector('[data-contexto]')?.classList.toggle('is-open', option.value === 'Reunião')
    })
  }
  go?.addEventListener('click', (event) => {
    event.preventDefault()
    if (document.querySelector('input[name="motivo"]:checked')?.value !== 'Reunião') root.dataset.estado = 'ativa'
  })
  document.querySelector('[data-encerrar]')?.addEventListener('click', () => { delete root.dataset.estado })
  // Painel lateral de edicao (Funcionarios).
  for (const button of document.querySelectorAll('[data-editar]')) {
    button.addEventListener('click', () => {
      root.dataset.painel = 'editar'
      document.querySelector('[data-painel-titulo]').textContent = `Editar ${button.dataset.editar}`
      document.querySelector('.drawer input')?.focus()
    })
  }
  const close = () => { delete root.dataset.painel }
  for (const button of document.querySelectorAll('[data-fechar]')) button.addEventListener('click', close)
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close() })
}

renderShell()
wire()
tick()
if (!frozen) window.setInterval(tick, 1000)
document.fonts.ready.then(() => { root.dataset.pronto = '1' })
