import { Component } from 'react'

export class RouteErrorBoundary extends Component {
  state = { hasError: false }

  static getDerivedStateFromError() {
    return { hasError: true }
  }

  componentDidUpdate(previousProps) {
    if (this.state.hasError && previousProps.resetKey !== this.props.resetKey) {
      this.setState({ hasError: false })
    }
  }

  retry = () => {
    window.location.reload()
  }

  render() {
    if (!this.state.hasError) return this.props.children

    return (
      <div className="card mx-auto max-w-2xl border-danger-line bg-danger-bg" role="alert">
        <div className="space-y-3">
          <p className="section-eyebrow text-danger">Portal SDK</p>
          <h2 className="text-xl font-bold text-ink">Nao foi possivel carregar este modulo.</h2>
          <p className="text-sm leading-6 text-ink-2">
            Atualize a pagina ou tente novamente. Caso o problema persista, acione o suporte responsavel pelo Portal SDK.
          </p>
          <button className="btn-secondary mt-2" onClick={this.retry} type="button">Tentar novamente</button>
        </div>
      </div>
    )
  }
}
