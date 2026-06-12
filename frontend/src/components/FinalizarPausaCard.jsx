export function FinalizarPausaCard({ canFinish, onFinish, loading }) {
  return (
    <section className="card flex flex-col justify-between gap-4">
      <div>
        <h2 className="font-bold">Finalizar pausa</h2>
        <p className="text-sm text-slate-400">
          {canFinish ? 'Sua pausa está ativa.' : 'Nenhuma pausa ativa para finalizar.'}
        </p>
      </div>
      <button className="btn-secondary w-full" onClick={onFinish} disabled={!canFinish || loading}>
        {loading ? 'Finalizando...' : 'Finalizar pausa'}
      </button>
    </section>
  )
}
