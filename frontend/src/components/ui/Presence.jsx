import { Icon } from './Icon'
import {
  PARTIAL_MARKER_LEGEND,
  PRESENCE_LEGEND,
  daySummaryAriaLabel,
  presenceDaySummary,
  presenceLegendItem,
} from '../../lib/presenceLegend'

// F5: situação com rótulo, ícone e forma da legenda única (desenho 5.1).
export function PresenceBadge({ status, label }) {
  const item = presenceLegendItem(status)
  if (item === null) return null
  return (
    <span className={`status-badge gap-1 ${item.tone}`}>
      <Icon name={item.icon} className="h-3 w-3 shrink-0" />
      {label || item.label}
    </span>
  )
}

// Marcador da parcial; com o horário (campo parcial), no nome acessível.
export function PartialMarker({ time = null }) {
  const label = time ? `${PARTIAL_MARKER_LEGEND.label} ${time}` : PARTIAL_MARKER_LEGEND.label
  return (
    <span aria-label={label} className="inline-flex shrink-0" role="img" title={label}>
      <Icon name={PARTIAL_MARKER_LEGEND.icon} className="h-3 w-3" />
    </span>
  )
}

// Resumo do dia por situação ("P 9 · R 6 · A 2 · S 1"); cada técnico conta
// uma vez, pela situação do dia.
export function PresenceDaySummary({ events }) {
  const summary = presenceDaySummary(events)
  if (summary.total === 0) return null
  return (
    <div aria-label={daySummaryAriaLabel(summary)} className="flex flex-wrap gap-1" role="group">
      {PRESENCE_LEGEND.filter((item) => summary[item.status] > 0).map((item) => (
        <span aria-hidden="true" className={`inline-flex items-center gap-0.5 rounded-md border px-1.5 py-0.5 text-[11px] font-bold ${item.tone}`} key={item.status} title={item.label}>
          <Icon name={item.icon} className="h-3 w-3" />{item.letter} {summary[item.status]}
        </span>
      ))}
      {summary.partial > 0 && <span aria-hidden="true" className="inline-flex items-center text-slate-400" title={PARTIAL_MARKER_LEGEND.label}><Icon name={PARTIAL_MARKER_LEGEND.icon} className="h-3 w-3" /></span>}
    </div>
  )
}

export function PresenceLegend() {
  return (
    <>
      {PRESENCE_LEGEND.map((item) => (
        <span className={`status-badge gap-1 ${item.tone}`} key={item.status} title={item.shape}>
          <Icon name={item.icon} className="h-3 w-3" />{item.letter} · {item.label}
        </span>
      ))}
      <span className="status-badge status-neutral gap-1" title={PARTIAL_MARKER_LEGEND.shape}>
        <Icon name={PARTIAL_MARKER_LEGEND.icon} className="h-3 w-3" />{PARTIAL_MARKER_LEGEND.label}
      </span>
    </>
  )
}
