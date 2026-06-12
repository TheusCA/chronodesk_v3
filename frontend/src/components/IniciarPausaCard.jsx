import { useState } from 'react'
import Select from 'react-select'

const reasons = [
  { value: 'Café', label: 'Café' },
  { value: 'Pessoal', label: 'Pessoal' },
  { value: 'Reunião', label: 'Reunião com aprovação' },
]

const selectStyles = {
  control: (base) => ({ ...base, background: '#0f172a', borderColor: '#334155', minHeight: 44 }),
  menu: (base) => ({ ...base, background: '#1e2a3a' }),
  option: (base, state) => ({ ...base, background: state.isFocused ? '#334155' : '#1e2a3a', color: '#f8fafc' }),
  singleValue: (base) => ({ ...base, color: '#f8fafc' }),
  input: (base) => ({ ...base, color: '#f8fafc' }),
}

export function IniciarPausaCard({ onStart, onRequest, loading }) {
  const [reason, setReason] = useState(null)
  const [observation, setObservation] = useState('')
  const meeting = reason?.value === 'Reunião'

  async function submit() {
    if (!reason) return
    const result = meeting
      ? await onRequest(reason.value, observation)
      : await onStart(reason.value)
    if (!result) return
    setReason(null)
    setObservation('')
  }

  return (
    <section className="card space-y-4">
      <div>
        <h2 className="font-bold">Iniciar pausa</h2>
        <p className="text-sm text-slate-400">Escolha o motivo da sua pausa.</p>
      </div>
      <Select options={reasons} value={reason} onChange={setReason} styles={selectStyles} placeholder="Selecione o motivo" />
      {meeting && (
        <textarea
          className="field min-h-24"
          value={observation}
          onChange={(event) => setObservation(event.target.value)}
          placeholder="Contexto da reunião"
          maxLength={500}
        />
      )}
      <button className="btn-primary w-full" onClick={submit} disabled={loading || !reason || (meeting && !observation.trim())}>
        {loading ? 'Processando...' : meeting ? 'Solicitar aprovação' : 'Iniciar pausa'}
      </button>
    </section>
  )
}
