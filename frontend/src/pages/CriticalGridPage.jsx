import { useRef, useState } from 'react'
import { Dialog } from '../components/ui/Dialog'
import { Segmented } from '../components/ui/Segmented'
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States'
import { useResource } from '../hooks/useResource'
import { apiUrl } from '../lib/api'
import { FIXED_TONES, dayHeader, dayLabel, extendSelection, inSelection, moveFocus, monthOf, toneClass, weekTitle } from '../lib/criticalGrid'
import { DayDialog, DayReadout } from './criticalGrid/DayDialog'
import './criticalGrid/criticalGrid.css'

const VIEW_OPTIONS = [
  { value: 'month', label: 'Mês' },
  { value: 'week', label: 'Semana' },
]
const MARKERS = { troca: { text: '⇄', label: 'veio de troca' }, excecao: { text: '✎', label: 'veio de exceção' } }

// Uma célula: o botão do dia (Enter abre; setas andam; Shift+clique ou arrastar seleciona).
function DayCell({ cell, date, today, selected, onPick, onDrag, onKeyNav, position, cardDay = false }) {
  const marker = cell.marker ? MARKERS[cell.marker] : null
  const classes = ['esc-cell', toneClass(cell.tone), date === today ? 'esc-today' : '', selected ? 'esc-selected' : '', marker ? 'esc-has-marker' : '', cell.in_month ? '' : 'esc-outside'].filter(Boolean).join(' ')
  return (
    <button
      aria-label={`${dayLabel(date)}: ${cell.label}${cell.hours && !cell.label.includes(cell.hours) ? ` ${cell.hours}` : ''}${marker ? `, ${marker.label}` : ''}${cell.detail ? `, ${cell.detail}` : ''}`}
      aria-pressed={selected}
      className={classes}
      data-esc-cell={position}
      data-esc-date={date}
      disabled={!cell.in_month}
      onClick={(event) => onPick(event)}
      onKeyDown={onKeyNav}
      onMouseDown={(event) => { if (!event.shiftKey && event.button === 0) onDrag('start') }}
      onMouseEnter={(event) => { if (event.buttons === 1) onDrag('move') }}
      type="button"
    >
      {cardDay && <span className="esc-card-day">{dayHeader(date)}</span>}
      <span className="esc-cell-label">{cell.label}</span>
      {cell.detail && <span className="esc-cell-detail">{cell.detail}</span>}
      {marker && <span aria-hidden="true" className="esc-cell-marker" title={marker.label}>{marker.text}</span>}
    </button>
  )
}

// Uma semana: grade (computador) e cartões por pessoa (celular).
function WeekBlock({ week, grid, open, selection, onSelect, onOpen, large = false }) {
  const containerRef = useRef(null)
  const people = grid.people
  const dragRef = useRef(null)

  const focusCell = (row, column) => {
    containerRef.current?.querySelector(`.esc-grid [data-esc-cell="${row}:${column}"]`)?.focus()
  }

  const handlers = (person, date, row, column) => ({
    onPick: (event) => {
      if (event.shiftKey) {
        const next = extendSelection(selection, person.id, date)
        onSelect(next)
        if (next.from !== next.to) onOpen(next, event.currentTarget)
        return
      }
      if (dragRef.current && dragRef.current.from !== dragRef.current.to && dragRef.current.employeeId === person.id) {
        const range = dragRef.current
        dragRef.current = null
        onOpen(range, event.currentTarget)
        return
      }
      dragRef.current = null
      const single = { employeeId: person.id, from: date, to: date }
      onSelect(single)
      onOpen(single, event.currentTarget)
    },
    onDrag: (phase) => {
      if (phase === 'start') {
        dragRef.current = { employeeId: person.id, from: date, to: date }
        return
      }
      if (dragRef.current && dragRef.current.employeeId === person.id) {
        dragRef.current = extendSelection(dragRef.current, person.id, date)
        onSelect(dragRef.current)
      }
    },
    onKeyNav: (event) => {
      if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
      event.preventDefault()
      const [nextRow, nextColumn] = moveFocus(row, column, event.key, people.length)
      if (event.shiftKey && nextRow === row) {
        onSelect(extendSelection(selection?.employeeId === person.id ? selection : { employeeId: person.id, from: date, to: date }, person.id, week.days[nextColumn]))
      }
      focusCell(nextRow, nextColumn)
    },
  })

  return (
    <details className={`esc-week${week.current ? ' esc-week-current' : ''}`} data-esc-week={week.iso} open={open} ref={containerRef}>
      <summary><span>{weekTitle(week)}</span>{week.current && <span className="esc-week-badge">atual</span>}</summary>
      <div className={`esc-grid${large ? ' esc-grid-large' : ''}`} role="grid" aria-label={weekTitle(week)}>
        <div className="esc-grid-head" role="columnheader">Técnico</div>
        {week.days.map((date) => <div className={`esc-grid-head${date === grid.today ? ' esc-today' : ''}`} key={date} role="columnheader">{dayHeader(date)}</div>)}
        {people.map((person, row) => (
          <div className="esc-row" key={person.id} role="row">
            <div className="esc-grid-name" role="rowheader" title={person.name}>{person.short_name}</div>
            {week.days.map((date, column) => (
              <div key={date} role="gridcell">
                <DayCell cell={grid.cells[`${person.id}:${date}`]} date={date} position={`${row}:${column}`} selected={inSelection(selection, person.id, date)} today={grid.today} {...handlers(person, date, row, column)} />
              </div>
            ))}
          </div>
        ))}
      </div>
      <div className="esc-cards" data-esc-cards="">
        {people.map((person, row) => (
          <section aria-label={`${person.name}, ${weekTitle(week)}`} className="esc-card" key={person.id}>
            <h3 className="esc-card-name">{person.short_name}</h3>
            {week.days.map((date, column) => (
              <DayCell cardDay cell={grid.cells[`${person.id}:${date}`]} date={date} key={date} position={`c${row}:${column}`} selected={inSelection(selection, person.id, date)} today={grid.today} {...handlers(person, date, row, column)} />
            ))}
          </section>
        ))}
      </div>
    </details>
  )
}

// [D-ESC-4] Resumo do mês, como a Capa da planilha.
function MonthSummary({ grid }) {
  const used = grid.roles.filter((role) => grid.summary.some((row) => (row.roles[String(role.id)] ?? 0) > 0))
  const names = new Map(grid.people.map((person) => [person.id, person.short_name]))
  return (
    <section className="card space-y-3" data-esc-summary="">
      <h2 className="font-semibold text-ink">Resumo do mês</h2>
      <div className="overflow-x-auto">
        <table className="esc-summary">
          <caption className="sr-only">Dias de cada papel, home, presencial e % presencial por técnico</caption>
          <thead>
            <tr>
              <th scope="col">Técnico</th>
              {used.map((role) => <th key={role.id} scope="col">{role.name}</th>)}
              <th scope="col">Dias home</th>
              <th scope="col">Dias presencial</th>
              <th scope="col">% presencial</th>
            </tr>
          </thead>
          <tbody>
            {grid.summary.map((row) => (
              <tr key={row.employee_id}>
                <td>{names.get(row.employee_id)}</td>
                {used.map((role) => <td key={role.id}>{row.roles[String(role.id)] ?? 0}</td>)}
                <td>{row.home}</td>
                <td>{row.onsite}</td>
                <td>{row.onsite_pct === null ? '—' : `${row.onsite_pct}%`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

// [Escala do Crítico em grade] Tela para todos os perfis; o diálogo do dia só
// para quem tem escalas.manage (o servidor confere de novo).
export function CriticalGridPage({ notify }) {
  const [month, setMonth] = useState(null)
  const [view, setView] = useState('month')
  const [weekIndex, setWeekIndex] = useState(null)
  const [selection, setSelection] = useState(null)
  const [dialog, setDialog] = useState(null)
  // [Achado das capturas, 2026-11-25] No Mês, todas as semanas abertas, como
  // na planilha; "Recolher todas"/"Expandir todas" vale para todas.
  const [weeksOpen, setWeeksOpen] = useState(true)
  const returnRef = useRef(null)
  const resource = useResource(`portal/critical_grid.php${month ? `?month=${month}` : ''}`)
  const grid = resource.data

  if (resource.loading && !grid) return <LoadingState />
  if (resource.error && !grid) return <ErrorState message={resource.error.message} onRetry={resource.refresh} />
  if (!grid) return null

  const currentWeek = Math.max(0, grid.weeks.findIndex((week) => week.current))
  const chosenWeek = weekIndex ?? currentWeek
  const go = (key) => { setMonth(key); setWeekIndex(null); setSelection(null) }
  const open = (range, target) => { returnRef.current = target; setDialog(range) }
  const person = dialog ? grid.people.find((item) => item.id === dialog.employeeId) : null
  const close = () => { setDialog(null); setSelection(null) }
  const weeks = view === 'week' ? [grid.weeks[Math.min(chosenWeek, grid.weeks.length - 1)]] : grid.weeks
  const legendRoles = grid.roles.filter((role) => role.active)

  return (
    <div className="esc-page" data-esc-page="">
      <div className="esc-toolbar">
        <div className="esc-toolbar-group">
          <button className="btn-secondary" data-esc-prev="" onClick={() => go(grid.month.prev)} type="button">Anterior</button>
          <button className="btn-secondary" data-esc-today-btn="" onClick={() => go(monthOf(grid.today))} type="button">Hoje</button>
          <button className="btn-secondary" data-esc-next="" onClick={() => go(grid.month.next)} type="button">Próximo</button>
        </div>
        <div className="esc-toolbar-group">
          <Segmented label="Visão" onChange={setView} options={VIEW_OPTIONS} value={view} />
          {view === 'month' && (
            <button aria-expanded={weeksOpen} className="btn-secondary" data-esc-toggle-weeks="" onClick={() => setWeeksOpen((value) => !value)} type="button">{weeksOpen ? 'Recolher todas' : 'Expandir todas'}</button>
          )}
          {view === 'week' && (
            <select aria-label="Semana" data-esc-week-pick="" onChange={(event) => setWeekIndex(Number(event.target.value))} value={Math.min(chosenWeek, grid.weeks.length - 1)}>
              {grid.weeks.map((week, index) => <option key={week.start} value={index}>{weekTitle(week)}</option>)}
            </select>
          )}
          <a className="btn-secondary" data-esc-export="" download href={apiUrl(`portal/critical_grid.php?month=${grid.month.key}&format=xlsx`)}>Exportar Excel</a>
        </div>
      </div>
      <h2 className="section-title esc-title" data-esc-title="">{grid.month.label}</h2>
      <div aria-label="Legenda das cores" className="esc-legend" data-esc-legend="" role="list">
        {legendRoles.map((role) => <span className={`esc-legend-item ${toneClass(`papel-${role.color_token || 'areia'}`)}`} key={role.id} role="listitem">{role.label}</span>)}
        {FIXED_TONES.map((item) => <span className={`esc-legend-item ${toneClass(item.tone)}`} key={item.tone} role="listitem">{item.label}</span>)}
        <span className="text-xs text-muted">⇄ troca · ✎ exceção</span>
      </div>
      {grid.people.length === 0 ? <EmptyState description="Ninguém ativo na equipe Crítico." title="Sem pessoas no Crítico" /> : (
        <>
          {weeks.map((week) => <WeekBlock grid={grid} key={week.start} large={view === 'week'} onOpen={open} onSelect={setSelection} open={view === 'week' || weeksOpen} selection={selection} week={week} />)}
          <MonthSummary grid={grid} />
        </>
      )}
      {dialog && grid.can_manage && (
        <DayDialog grid={grid} key={`${dialog.employeeId}:${dialog.from}:${dialog.to}`} notify={notify} onClose={close} onSaved={async () => { close(); await resource.refresh() }} returnFocusRef={returnRef} selection={dialog} />
      )}
      {dialog && !grid.can_manage && person && (
        <Dialog onClose={close} returnFocusRef={returnRef} size="max-w-md" title={`${person.name} · ${dayLabel(dialog.from)}`}>
          <DayReadout cell={grid.cells[`${dialog.employeeId}:${dialog.from}`]} date={dialog.from} personName={person.name} />
        </Dialog>
      )}
    </div>
  )
}
