import { Icon } from './Icon'

// [R2] Selo de estado ou de situação. Nunca só a cor: sempre um rótulo, e o
// ícone quando a situação tem um (legenda do F5 e do F7, presenceLegend.ts).
// tone: success, info, warning, danger, neutral, ou a classe de uma situação
// (presence-onsite, presence-pause...), que já traz a cor nos dois temas.
const TONES = { success: 'status-success', info: 'status-info', warning: 'status-warning', danger: 'status-danger', neutral: 'status-neutral' }

function toneClass(tone) {
  if (TONES[tone]) return TONES[tone]
  return typeof tone === 'string' && /^presence-[a-z-]+$/.test(tone) ? tone : TONES.neutral
}

export function Tag({ tone = 'neutral', icon = null, title = undefined, children }) {
  return (
    <span className={`status-badge ${toneClass(tone)}`} title={title}>
      {icon && <Icon className="h-3.5 w-3.5 shrink-0" name={icon} />}
      {children}
    </span>
  )
}
