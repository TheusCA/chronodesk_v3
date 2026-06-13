import { useState } from 'react'

export function LoginAdmin({ onSubmit, loading }) {
  const [form, setForm] = useState({ username: '', password: '' })

  function submit(event) {
    event.preventDefault()
    onSubmit(form).finally(() => setForm((current) => ({ ...current, password: '' })))
  }

  return (
    <form className="card space-y-4" onSubmit={submit}>
      <div>
        <h2 className="text-lg font-bold">Acesso administrativo</h2>
        <p className="text-sm text-slate-400">Disponível para contas AD autorizadas.</p>
      </div>
      <label className="block text-sm font-medium">
        Login AD
        <input
          className="field mt-1"
          value={form.username}
          onChange={(event) => setForm({ ...form, username: event.target.value })}
          autoComplete="username"
          required
        />
      </label>
      <label className="block text-sm font-medium">
        Senha
        <input
          className="field mt-1"
          type="password"
          value={form.password}
          onChange={(event) => setForm({ ...form, password: event.target.value })}
          autoComplete="current-password"
          required
        />
      </label>
      <button className="btn-secondary w-full" disabled={loading}>{loading ? 'Validando...' : 'Entrar como gestor/admin'}</button>
    </form>
  )
}
