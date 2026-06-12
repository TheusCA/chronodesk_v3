export function AdminDashboard({ requests, onApprove, onReject, loading }) {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-2xl font-bold">Painel administrativo</h2>
        <p className="text-slate-400">Solicitações aguardando decisão.</p>
      </div>
      <div className="grid gap-4">
        {requests.length === 0 && <div className="card text-slate-400">Nenhuma solicitação pendente.</div>}
        {requests.map((request) => (
          <article className="card flex flex-col justify-between gap-4 md:flex-row md:items-center" key={request.id}>
            <div>
              <h3 className="font-semibold">{request.nome}</h3>
              <p className="text-sm text-slate-400">{request.motivo} · Equipe {request.equipe?.toUpperCase()}</p>
              <p className="mt-2 text-sm">{request.observacao || 'Sem observação.'}</p>
            </div>
            <div className="flex gap-2">
              <button className="btn-primary" disabled={loading} onClick={() => onApprove(request.id)}>Aprovar</button>
              <button className="btn-danger" disabled={loading} onClick={() => onReject(request.id)}>Rejeitar</button>
            </div>
          </article>
        ))}
      </div>
      <a className="btn-secondary" href="../admin.php">Abrir configurações avançadas</a>
    </div>
  )
}
