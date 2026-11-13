// [R2] Filtro segmentado: as opções lado a lado, cada uma com a contagem
// (por exemplo, "Todos 16 · Disponíveis 10 · Em pausa 2"). Uma escolha por vez,
// marcada com aria-pressed; o grupo tem nome. Alvo de 40 px em qualquer largura.
// Feito para as telas novas (F10, CHGs) e para as telas do R4 (Pausas e Visão
// operacional); as abas de navegação continuam com .tab-button.
export function Segmented({ label, options, value, onChange }) {
  return (
    <div aria-label={label} className="segmented" role="group">
      {options.map((option) => (
        <button aria-pressed={option.value === value} className="segmented-option" key={option.value} onClick={() => onChange(option.value)} type="button">
          <span>{option.label}</span>
          {Number.isInteger(option.count) && <span className="segmented-count">{option.count}</span>}
        </button>
      ))}
    </div>
  )
}
