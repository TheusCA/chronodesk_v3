import { LoginCI } from '../components/LoginCI'
import { BrandMark } from '../components/BrandMark'

export function LoginPage({ onLoginCI, loading }) {
  return (
    <main className="grid min-h-screen place-items-center px-4 py-10">
      <div className="w-full max-w-5xl">
        <div className="mb-8 text-center">
          <BrandMark className="mx-auto mb-4 h-16 w-16" />
          <h1 className="text-3xl font-black tracking-tight text-ink">Portal SDK</h1>
          <p className="mt-2 text-muted">Operações, métricas e aprovações em um único lugar.</p>
        </div>
        <div className="mx-auto max-w-md">
          <LoginCI onSubmit={onLoginCI} loading={loading} />
          <p className="mt-5 text-center text-xs font-medium text-muted">Desenvolvido por Matheus Camargo</p>
        </div>
      </div>
    </main>
  )
}
