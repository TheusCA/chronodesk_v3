import { motion as Motion } from 'framer-motion'

const statusColors = {
  disponivel: 'text-chrono-green',
  em_pausa: 'text-chrono-yellow',
  pendente: 'text-chrono-yellow',
  inativo: 'text-chrono-red',
}

export function StatusColaboradorCard({ funcionario }) {
  const pending = funcionario.status_aprovacao === 'pendente'
  const status = pending ? 'pendente' : (funcionario.em_pausa ? 'em_pausa' : funcionario.disponibilidade?.status)
  const label = pending ? 'Aguardando aprovação' : (funcionario.em_pausa ? 'Em pausa' : funcionario.disponibilidade?.label)

  return (
    <Motion.article className="card" whileHover={{ y: -3 }} transition={{ duration: 0.18 }}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="font-semibold">{funcionario.nome}</h3>
          <p className="text-xs uppercase tracking-wider text-slate-500">{funcionario.equipe}</p>
        </div>
        <span className={`text-sm font-semibold ${statusColors[status] || 'text-chrono-red'}`}>{label || 'Indisponível'}</span>
      </div>
      {funcionario.em_pausa && (
        <p className="mt-4 text-sm text-slate-300">
          {funcionario.motivo_pausa} · {Math.floor((funcionario.tempo_pausa || 0) / 60)} min
        </p>
      )}
    </Motion.article>
  )
}
