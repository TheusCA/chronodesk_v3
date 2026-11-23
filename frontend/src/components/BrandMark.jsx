// [Marca, 2026-11-23] O desenho do favicon (opção B, "chaves com pausa": as
// chaves de código com o sinal de pausa entre elas; docs/design/marca) em SVG
// inline, na mesma geometria do favicon.svg (viewBox 64 x 64). Sem cor fixa:
// a placa usa o token do bg-action como preenchimento (fill-action: num
// <rect> de SVG o fundo é o fill), as chaves o currentColor do
// text-action-ink e as barras da pausa o token da situação "Em pausa"
// (pause-line: contraste de 10,4:1 no tema claro e 6,4:1 no escuro contra a
// placa; o pause do texto daria 2,4:1 e 1,4:1). Sem atributo style (CSP
// style-src 'self'). Decorativa: o nome "Portal SDK" vem sempre escrito ao lado.
export function BrandMark({ className = 'h-10 w-10' }) {
  return (
    <svg aria-hidden="true" className={`shrink-0 select-none ${className}`} data-brand-mark="" focusable="false" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
      <rect className="fill-action" height="64" rx="14" width="64" />
      <g className="text-action-ink" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="4.5">
        <path d="M22 15.5 C17.5 15.5 16.5 18 16.5 22 L16.5 26 C16.5 29.5 15 32 11.5 32 C15 32 16.5 34.5 16.5 38 L16.5 42 C16.5 46 17.5 48.5 22 48.5" />
        <path d="M42 15.5 C46.5 15.5 47.5 18 47.5 22 L47.5 26 C47.5 29.5 49 32 52.5 32 C49 32 47.5 34.5 47.5 38 L47.5 42 C47.5 46 46.5 48.5 42 48.5" />
      </g>
      <g className="fill-pause-line" data-brand-pause="">
        <rect height="18" rx="2.5" width="5" x="25.5" y="23" />
        <rect height="18" rx="2.5" width="5" x="33.5" y="23" />
      </g>
    </svg>
  )
}
