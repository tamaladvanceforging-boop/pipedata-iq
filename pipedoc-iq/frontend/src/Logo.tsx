/** Original PipeDoc IQ mark: a pipe elbow whose flange doubles as a document with a spark of automation. */
export function LogoMark({ size = 32 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 64 64" role="img" aria-label="PipeDoc IQ logo">
      <rect width="64" height="64" rx="14" fill="#18181B" />
      <path d="M14 46V26a10 10 0 0 1 10-10h14" fill="none" stroke="#EA580C" strokeWidth="9" strokeLinecap="butt" />
      <rect x="8" y="42" width="12" height="6" rx="1" fill="#FB923C" />
      <rect x="34" y="11" width="6" height="12" rx="1" fill="#FB923C" />
      <path d="M34 30h14l6 6v16a3 3 0 0 1-3 3H34a3 3 0 0 1-3-3V33a3 3 0 0 1 3-3z" fill="#F4F4F5" />
      <path d="M48 30v6h6" fill="#D4D4D8" />
      <path d="M36 42h14M36 47h10" stroke="#18181B" strokeWidth="2" strokeLinecap="round" />
      <path d="M52 14l1.6 3.4L57 19l-3.4 1.6L52 24l-1.6-3.4L47 19l3.4-1.6z" fill="#FB923C" />
    </svg>
  );
}
export function Brand({ collapsed }: { collapsed?: boolean }) {
  return (<div className="flex items-center gap-2"><LogoMark />{!collapsed && <div className="leading-tight"><div className="font-head text-base font-extrabold">PipeDoc <span className="text-brand">IQ</span></div><div className="text-[10px] opacity-60">Engineering data → print-ready docs</div></div>}</div>);
}
