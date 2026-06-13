import { LoginAdmin } from '../components/LoginAdmin'
import { LoginCI } from '../components/LoginCI'

export function LoginPage({ onLoginCI, onLoginAdmin, loading }) {
  return (
    <main className="relative grid min-h-screen place-items-center overflow-hidden px-4 py-10">
      <div className="login-glow login-glow-left" />
      <div className="login-glow login-glow-right" />
      <div className="relative z-10 w-full max-w-5xl">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-blue-600 text-lg font-black shadow-xl shadow-blue-600/30">CD</div>
          <h1 className="text-3xl font-black tracking-tight text-white">Portal Operacional SDK</h1>
          <p className="mt-2 text-slate-400">ChronoDesk, operações, métricas e aprovações em um único lugar.</p>
        </div>
        <div className="grid gap-5 md:grid-cols-2">
          <LoginCI onSubmit={onLoginCI} loading={loading} />
          <LoginAdmin onSubmit={onLoginAdmin} loading={loading} />
        </div>
      </div>
    </main>
  )
}
