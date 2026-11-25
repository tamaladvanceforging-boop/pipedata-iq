import { createContext, useCallback, useContext, useEffect, useMemo, useState, ReactNode } from 'react';
import { api, Boot } from './api';
import { CheckCircle2, AlertTriangle, XCircle, Info } from 'lucide-react';

export type Route = { page: string; sub?: string };
interface Toast { id: number; kind: 'ok' | 'err' | 'info'; msg: string }
interface Ctx {
  boot: Boot; route: Route; go: (page: string, sub?: string) => void;
  toast: (msg: string, kind?: Toast['kind']) => void; dark: boolean; setDark: (b: boolean) => void;
  palette: boolean; setPalette: (b: boolean) => void; confirm: (msg: string) => Promise<boolean>;
}
const C = createContext<Ctx>(null as any);
export const useApp = () => useContext(C);

export function Provider({ children }: { children: ReactNode }) {
  const [boot, setBoot] = useState<Boot | null>(null); const [err, setErr] = useState('');
  const [route, setRoute] = useState<Route>(() => { const h = location.hash.replace('#/', '').split('/'); return { page: h[0] || 'dashboard', sub: h[1] }; });
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [dark, setDarkS] = useState(() => { try { return localStorage.getItem('pdq_dark') === '1'; } catch { return false; } });
  const [palette, setPalette] = useState(false);
  const [dlg, setDlg] = useState<{ msg: string; res: (b: boolean) => void } | null>(null);

  useEffect(() => { api<Boot>('bootstrap').then(setBoot).catch((e) => setErr(e.message)); }, []);
  useEffect(() => { document.documentElement.classList.toggle('dark', dark); }, [dark]);
  useEffect(() => { const f = () => { const h = location.hash.replace('#/', '').split('/'); setRoute({ page: h[0] || 'dashboard', sub: h[1] }); }; window.addEventListener('hashchange', f); return () => window.removeEventListener('hashchange', f); }, []);
  const go = useCallback((page: string, sub?: string) => { location.hash = '#/' + page + (sub ? '/' + sub : ''); }, []);
  const toast = useCallback((msg: string, kind: Toast['kind'] = 'ok') => { const id = Date.now() + Math.random(); setToasts((t) => [...t, { id, kind, msg }]); setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4500); }, []);
  const setDark = (b: boolean) => { setDarkS(b); try { localStorage.setItem('pdq_dark', b ? '1' : '0'); } catch { /* ignore */ } };
  const confirm = useCallback((msg: string) => new Promise<boolean>((res) => setDlg({ msg, res })), []);

  const v = useMemo(() => (boot ? { boot, route, go, toast, dark, setDark, palette, setPalette, confirm } : null), [boot, route, go, toast, dark, palette, confirm]);
  if (err) return <div className="p-8 text-err">Could not start PipeDoc IQ: {err}. Make sure you are logged in to WordPress as an administrator.</div>;
  if (!v) return <div className="flex h-full items-center justify-center text-sm opacity-60">Loading PipeDoc IQ…</div>;
  const ic = { ok: <CheckCircle2 size={16} className="text-ok" />, err: <XCircle size={16} className="text-err" />, info: <Info size={16} className="text-info" /> };
  return (
    <C.Provider value={v}>
      {children}
      <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2" role="status" aria-live="polite">
        {toasts.map((t) => <div key={t.id} className="card flex max-w-sm items-start gap-2 px-3 py-2 shadow-lg">{ic[t.kind]}<span>{t.msg}</span></div>)}
      </div>
      {dlg && (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-md p-5">
            <div className="mb-4 flex gap-2"><AlertTriangle className="shrink-0 text-warn" size={20} /><p>{dlg.msg}</p></div>
            <div className="flex justify-end gap-2"><button className="btn-ghost" onClick={() => { dlg.res(false); setDlg(null); }}>Cancel</button><button className="btn-danger" onClick={() => { dlg.res(true); setDlg(null); }}>Confirm</button></div>
          </div>
        </div>
      )}
    </C.Provider>
  );
}
