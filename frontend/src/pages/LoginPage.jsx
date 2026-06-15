import { LoginAdmin } from '../components/LoginAdmin'
import { LoginCI } from '../components/LoginCI'
import { BrandMark } from '../components/BrandMark'

export function LoginPage({ onLoginCI, onLoginAdmin, loading }) {
  return (
    <main className="tech-login-shell relative grid min-h-screen place-items-center overflow-hidden px-4 py-10">
      <div className="tech-grid" />
      <div className="tech-horizon" />
      <div className="tech-node tech-node-one" />
      <div className="tech-node tech-node-two" />
      <div className="tech-node tech-node-three" />
      <div className="login-glow login-glow-left" />
      <div className="login-glow login-glow-right" />
      <div className="relative z-10 w-full max-w-5xl">
        <div className="mb-8 text-center">
          <BrandMark className="mx-auto mb-4 h-16 w-16 drop-shadow-[0_14px_28px_rgba(37,99,235,0.35)]" />
          <h1 className="text-3xl font-black tracking-tight text-white">Portal SDK</h1>
          <p className="mt-2 text-slate-400">Operações, métricas e aprovações em um único lugar.</p>
        </div>
        <div className="grid gap-5 md:grid-cols-2">
          <LoginCI onSubmit={onLoginCI} loading={loading} />
          <LoginAdmin onSubmit={onLoginAdmin} loading={loading} />
        </div>
      </div>
    </main>
  )
}
