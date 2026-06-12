import { Header } from './Header'

export function AppLayout({ session, activeView, onViewChange, onLogout, children }) {
  return (
    <div className="min-h-screen">
      <Header session={session} activeView={activeView} onViewChange={onViewChange} onLogout={onLogout} />
      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{children}</main>
    </div>
  )
}
