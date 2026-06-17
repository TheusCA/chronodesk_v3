import { useState } from 'react'

export function LoginCI({ onSubmit, loading }) {
  const [form, setForm] = useState({ login_ad: '', senha_ad: '' })

  function submit(event) {
    event.preventDefault()
    onSubmit(form).finally(() => setForm((current) => ({ ...current, senha_ad: '' })))
  }

  return (
    <form className="card space-y-4" onSubmit={submit}>
      <div>
        <h2 className="text-lg font-bold">Acesso corporativo</h2>
        <p className="text-sm text-slate-400">Entre com sua conta AD. O perfil e as permissões sao reconhecidos automaticamente.</p>
      </div>
      <label className="block text-sm font-medium">
        Login AD
        <input
          className="field mt-1"
          value={form.login_ad}
          onChange={(event) => setForm({ ...form, login_ad: event.target.value })}
          autoComplete="username"
          placeholder="usuario ou usuario@paschoalotto.com.br"
          required
        />
      </label>
      <label className="block text-sm font-medium">
        Senha
        <input
          className="field mt-1"
          type="password"
          value={form.senha_ad}
          onChange={(event) => setForm({ ...form, senha_ad: event.target.value })}
          autoComplete="current-password"
          required
        />
      </label>
      <button className="btn-primary w-full" disabled={loading}>{loading ? 'Entrando...' : 'Entrar'}</button>
    </form>
  )
}
