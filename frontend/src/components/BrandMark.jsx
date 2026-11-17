// [Marca, 2026-11-16] O desenho do favicon (mostrador de timer, pausa e o
// ponto da situação "Em pausa"; docs/design/marca) em SVG inline, na mesma
// geometria do favicon.svg (viewBox 64 x 64). Sem cor fixa: a placa usa o
// token do bg-action como preenchimento (fill-action: num <rect> de SVG o
// fundo é o fill), o anel e as barras o currentColor do text-action-ink, e o
// ponto o token da situação "Em pausa"
// (pause-line: contraste de 10,4:1 no tema claro e 6,4:1 no escuro; o
// pause do texto daria 2,4:1 e 1,4:1). Sem atributo style (CSP style-src
// 'self'). Decorativa: o nome "Portal SDK" vem sempre escrito ao lado.
export function BrandMark({ className = 'h-10 w-10' }) {
  return (
    <svg aria-hidden="true" className={`shrink-0 select-none ${className}`} data-brand-mark="" focusable="false" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
      <rect className="fill-action" height="64" rx="14" width="64" />
      <g className="text-action-ink">
        <path d="M49 32 A17 17 0 1 1 32 15" fill="none" stroke="currentColor" strokeLinecap="round" strokeWidth="6" />
        <rect fill="currentColor" height="14" rx="2.75" width="5.5" x="25" y="25" />
        <rect fill="currentColor" height="14" rx="2.75" width="5.5" x="33.5" y="25" />
      </g>
      <circle className="fill-pause-line" cx="44" cy="20" data-brand-dot="" r="4" />
    </svg>
  )
}
