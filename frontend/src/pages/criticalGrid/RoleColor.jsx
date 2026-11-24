import { Field } from '../../components/ui/Field'
import { COLOR_LABELS, COLOR_TOKENS, colorClass } from '../../lib/criticalGrid'
import './criticalGrid.css'

// [D-ESC-13] Amostra da cor do papel (classe da paleta, sem atributo style).
// Carregado sob demanda pela tela de papéis: o CSS da paleta fica fora do CSS
// principal (limite próprio no qa:bundle).
export function RoleSwatch({ token, label = null }) {
  return (
    <span className="inline-flex items-center gap-2" data-role-swatch={token || 'areia'}>
      <span aria-hidden="true" className={`esc-swatch ${colorClass(token)}`} />
      <span>{label ?? COLOR_LABELS[token || 'areia']}</span>
    </span>
  )
}

// Seletor da cor do papel: a paleta fechada, com a amostra ao lado.
export function RoleColorPicker({ value, onChange }) {
  return (
    <Field help="Sem cor, a escala mostra areia." label="Cor na escala">
      <span className="flex items-center gap-2">
        <select data-role-color="" onChange={(event) => onChange(event.target.value)} value={value || ''}>
          <option value="">Sem cor (areia)</option>
          {COLOR_TOKENS.map((token) => <option key={token} value={token}>{COLOR_LABELS[token]}</option>)}
        </select>
        <span aria-hidden="true" className={`esc-swatch ${colorClass(value)}`} data-role-color-sample={value || 'areia'} />
      </span>
    </Field>
  )
}

export default RoleColorPicker
