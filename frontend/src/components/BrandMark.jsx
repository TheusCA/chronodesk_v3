// [R3] Marca em texto, sem imagem: não depende de arquivo fora do build
// (o sdk-icon.png quebrava no servidor simulado) e segue o tema pelos tokens.
// Decorativa: o nome "Portal SDK" vem sempre escrito ao lado.
export function BrandMark({ className = 'h-10 w-10 text-sm' }) {
  return (
    <span aria-hidden="true" className={`grid shrink-0 select-none place-items-center rounded-md bg-action font-semibold text-action-ink ${className}`} data-brand-mark="">
      SDK
    </span>
  )
}
