export function BrandMark({ className = 'h-10 w-10' }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      fill="none"
      viewBox="0 0 64 64"
      xmlns="http://www.w3.org/2000/svg"
    >
      <defs>
        <linearGradient id="sdk-mark-bg" x1="8" x2="56" y1="6" y2="58" gradientUnits="userSpaceOnUse">
          <stop stopColor="#2563EB" />
          <stop offset="1" stopColor="#0891B2" />
        </linearGradient>
        <linearGradient id="sdk-mark-line" x1="17" x2="47" y1="18" y2="46" gradientUnits="userSpaceOnUse">
          <stop stopColor="#E0F2FE" />
          <stop offset="1" stopColor="#7DD3FC" />
        </linearGradient>
      </defs>
      <rect width="60" height="60" x="2" y="2" fill="url(#sdk-mark-bg)" rx="17" />
      <path d="M18 21.5 12 32l6 10.5M46 21.5 52 32l-6 10.5" stroke="url(#sdk-mark-line)" strokeLinecap="round" strokeLinejoin="round" strokeWidth="4" />
      <path d="M25 20h8.5a6.5 6.5 0 0 1 0 13H29a6.5 6.5 0 0 0 0 13h10" stroke="white" strokeLinecap="round" strokeWidth="4" />
      <circle cx="40" cy="20" r="3" fill="#A5F3FC" />
      <circle cx="24" cy="46" r="3" fill="#A5F3FC" />
    </svg>
  )
}
