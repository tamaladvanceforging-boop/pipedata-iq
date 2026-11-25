import { createRoot } from 'react-dom/client';
import { useEffect, useMemo, useState } from 'react';
import { Archive as IArchive, ArrowLeftRight, BadgeCheck, Boxes, Building2, Circle, ClipboardList, Database, FileCog, FilePlus2, Flame, HardDriveDownload, History, KeyRound, Layers, LayoutDashboard, LifeBuoy, LogOut, Menu, Microscope, Moon, PackageCheck, Search, Settings as ISettings, Sparkles, Sun, Truck, Users } from 'lucide-react';
const ICONS: Record<string, any> = { LayoutDashboard, Database, Layers, Building2, Users, Truck, ClipboardList, Boxes, Flame, BadgeCheck, Microscope, PackageCheck, FileCog, FilePlus2, Archive: IArchive, Sparkles, KeyRound, ArrowLeftRight, History, HardDriveDownload, Settings: ISettings, LifeBuoy };
import './index.css';
import { Provider, useApp } from './ctx';
import { Brand } from './Logo';
import { api, Row } from './api';
import { Dashboard } from './pages/Dashboard';
import { Engineering } from './pages/Engineering';
import { Master } from './pages/Master';
import { EntityPage } from './pages/EntityPage';
import { HeatNumbers, PurchaseOrders, Inspection, Dispatch } from './pages/Workflows';
import { ImportExport } from './pages/ImportExport';
import { AISettings, AIAssistant } from './pages/AI';
import { Templates } from './pages/Templates';
import { Generator } from './pages/Generator';
import { Archive } from './pages/Archive';
import { CompanyProfiles, Backup, Settings, Help } from './pages/Misc';
import { ErrorBox } from './ui';

function Page({ id }: { id: string }) {
  switch (id) {
    case 'dashboard': return <Dashboard />;
    case 'engineering_dimensions': return <Engineering />;
    case 'master': return <Master />;
    case 'company_profiles': return <CompanyProfiles />;
    case 'customers': return <EntityPage entity="customers" />;
    case 'suppliers': return <EntityPage entity="suppliers" />;
    case 'purchase_orders': return <PurchaseOrders />;
    case 'material_grades': return <EntityPage entity="material_grades" title="Material Management" />;
    case 'heat_numbers': return <HeatNumbers />;
    case 'tc': return <Archive docType="Test Certificate (TC)" title="Test Certificates" />;
    case 'inspection_results': return <Inspection />;
    case 'dispatch_batches': return <Dispatch />;
    case 'templates': return <Templates />;
    case 'generator': return <Generator />;
    case 'documents': return <Archive />;
    case 'ai_assistant': return <AIAssistant />;
    case 'ai_settings': return <AISettings />;
    case 'import_export': return <ImportExport />;
    case 'activity_logs': return <EntityPage entity="activity_logs" title="Activity Logs" />;
    case 'backup': return <Backup />;
    case 'settings': return <Settings />;
    case 'help': return <Help />;
    default: return <ErrorBox msg="Page not found." />;
  }
}

function Palette() {
  const { boot, go, setPalette } = useApp(); const [q, setQ] = useState(''); const [hits, setHits] = useState<Row[]>([]); const [sel, setSel] = useState(0);
  const nav = useMemo(() => boot.nav.filter((n) => n.label.toLowerCase().includes(q.toLowerCase())), [boot, q]);
  useEffect(() => { if (q.trim().length < 2) { setHits([]); return; } const t = setTimeout(async () => { try { const [e, d] = await Promise.all([api('e/engineering_dimensions', { query: { search: q, per_page: 5 } }), api('e/documents', { query: { search: q, per_page: 5 } })]); setHits([...e.items.map((x: Row) => ({ k: 'engineering_dimensions', t: `${x.item_code} — ${x.item_type || x.category || ''} NPS ${x.nps ?? ''}` })), ...d.items.map((x: Row) => ({ k: 'documents', t: `${x.doc_number} — ${x.doc_type}` }))]); } catch { setHits([]); } }, 250); return () => clearTimeout(t); }, [q]);
  const all = [...nav.map((n) => ({ k: n.id, t: 'Go to ' + n.label })), ...hits]; const run = (i: number) => { if (all[i]) { go(all[i].k); setPalette(false); } };
  return (
    <div className="fixed inset-0 z-[90] flex items-start justify-center bg-black/50 pt-24" onMouseDown={(e) => e.target === e.currentTarget && setPalette(false)}>
      <div className="card w-full max-w-lg overflow-hidden shadow-2xl" role="dialog" aria-label="Command palette">
        <input autoFocus className="w-full border-b bg-transparent px-4 py-3 outline-none" style={{ borderColor: 'var(--line)' }} placeholder="Search pages, engineering items, documents…" value={q} onChange={(e) => { setQ(e.target.value); setSel(0); }}
          onKeyDown={(e) => { if (e.key === 'Escape') setPalette(false); if (e.key === 'ArrowDown') { e.preventDefault(); setSel(Math.min(sel + 1, all.length - 1)); } if (e.key === 'ArrowUp') { e.preventDefault(); setSel(Math.max(sel - 1, 0)); } if (e.key === 'Enter') run(sel); }} />
        <div className="max-h-80 overflow-auto">{all.map((a, i) => <button key={i} onMouseEnter={() => setSel(i)} onClick={() => run(i)} className={`block w-full px-4 py-2 text-left text-sm ${i === sel ? 'bg-brand text-white' : ''}`}>{a.t}</button>)}{all.length === 0 && <div className="p-4 text-sm opacity-60">No results</div>}</div>
      </div>
    </div>
  );
}

function Shell() {
  const { boot, route, go, dark, setDark, palette, setPalette } = useApp();
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem('pdq_collapsed') === '1'; } catch { return false; } });
  const [mobile, setMobile] = useState(false);
  useEffect(() => { try { localStorage.setItem('pdq_collapsed', collapsed ? '1' : '0'); } catch { /* ignore */ } }, [collapsed]);
  useEffect(() => { const f = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPalette(true); } if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') { e.preventDefault(); setCollapsed((c) => !c); } }; window.addEventListener('keydown', f); return () => window.removeEventListener('keydown', f); }, [setPalette]);
  useEffect(() => setMobile(false), [route.page]);
  const cur = boot.nav.find((n) => n.id === route.page);
  const icon = (n: string, s = 18) => { const I = ICONS[n] || Circle; return <I size={s} />; };
  return (
    <div className="flex h-full">
      {mobile && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={() => setMobile(false)} />}
      <aside className={`fixed z-40 flex h-full flex-col bg-graphite text-zinc-200 transition-all lg:static ${mobile ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'} ${collapsed ? 'w-16' : 'w-64'}`}>
        <div className="flex h-14 items-center justify-between px-3 text-white"><Brand collapsed={collapsed} /></div>
        <nav className="flex-1 overflow-y-auto px-2 pb-4" aria-label="Main">{boot.nav.map((n) => <button key={n.id} title={n.label} onClick={() => go(n.id)} className={`mb-0.5 flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition ${route.page === n.id ? 'bg-brand text-white' : 'hover:bg-white/10'}`}>{icon(n.icon)}{!collapsed && <span className="truncate">{n.label}</span>}</button>)}</nav>
        <button className="hidden border-t border-white/10 p-3 text-xs hover:bg-white/10 lg:block" onClick={() => setCollapsed(!collapsed)}>{collapsed ? '»' : '« Collapse'}</button>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center gap-3 border-b px-4" style={{ background: 'var(--card)', borderColor: 'var(--line)' }}>
          <button className="lg:hidden" aria-label="Menu" onClick={() => setMobile(true)}><Menu size={20} /></button>
          <nav className="hidden text-sm sm:block" aria-label="Breadcrumb"><span className="opacity-50">PipeDoc IQ</span> <span className="opacity-30">/</span> <b>{cur?.label || route.page}</b></nav>
          <button onClick={() => setPalette(true)} className="ml-auto flex w-full max-w-xs items-center gap-2 rounded-lg border px-3 py-1.5 text-left text-sm opacity-80" style={{ borderColor: 'var(--line)' }}><Search size={14} /><span className="flex-1 opacity-60">Search…</span><kbd className="rounded border px-1 text-[10px]">Ctrl K</kbd></button>
          <button className="rounded p-2 hover:bg-black/10" onClick={() => setDark(!dark)} aria-label="Toggle dark mode">{dark ? <Sun size={18} /> : <Moon size={18} />}</button>
          <a className="rounded p-2 hover:bg-black/10" href={new URL(window.PDQ.adminUrl, location.href).pathname} title="WordPress admin"><LogOut size={18} /></a>
        </header>
        <main className="flex-1 overflow-y-auto p-4 lg:p-6"><Page id={route.page} /></main>
        <footer className="border-t px-4 py-2 text-center text-xs opacity-60" style={{ borderColor: 'var(--line)' }}>Designed &amp; Developed by Tamal Roy Chowdhury</footer>
      </div>
      {palette && <Palette />}
    </div>
  );
}
createRoot(document.getElementById('root')!).render(<Provider><Shell /></Provider>);
