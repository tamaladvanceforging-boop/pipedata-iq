import { ReactNode, useEffect, useState } from 'react';
import { X, Inbox, Loader2, ChevronLeft, ChevronRight } from 'lucide-react';
import { api, Col, Row, label, list } from './api';

export const STATUS_COLOR: Record<string, string> = {
  verified: 'bg-ok', approved: 'bg-ok', issued: 'bg-ok', active: 'bg-ok', pass: 'bg-ok', open: 'bg-info', in_progress: 'bg-info', dispatched: 'bg-info',
  pending_review: 'bg-warn', pending: 'bg-warn', unverified: 'bg-warn', rejected: 'bg-err', fail: 'bg-err', draft: 'bg-draft', closed: 'bg-draft', inactive: 'bg-draft', queued: 'bg-sync',
};
export function Badge({ v }: { v: any }) {
  if (v === null || v === undefined || v === '') return null; const s = String(v);
  return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium text-white ${STATUS_COLOR[s] || 'bg-draft'}`}>{label(s)}</span>;
}
export const Spinner = () => <Loader2 className="animate-spin" size={16} />;
export function Empty({ title, hint }: { title: string; hint?: string }) { return <div className="flex flex-col items-center gap-2 py-14 text-center opacity-70"><Inbox size={34} /><div className="font-medium">{title}</div>{hint && <div className="max-w-sm text-xs">{hint}</div>}</div>; }
export function ErrorBox({ msg, retry }: { msg: string; retry?: () => void }) { return <div className="m-4 rounded-lg border border-err/40 bg-err/10 p-4 text-err">{msg} {retry && <button className="ml-2 underline" onClick={retry}>Retry</button>}</div>; }

export function Drawer({ title, onClose, children, wide }: { title: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  useEffect(() => { const f = (e: KeyboardEvent) => e.key === 'Escape' && onClose(); window.addEventListener('keydown', f); return () => window.removeEventListener('keydown', f); }, [onClose]);
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/40" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`flex h-full w-full ${wide ? 'max-w-3xl' : 'max-w-xl'} flex-col shadow-2xl`} style={{ background: 'var(--card)' }} role="dialog" aria-label={title}>
        <div className="flex items-center justify-between border-b px-5 py-3" style={{ borderColor: 'var(--line)' }}><h2 className="font-head text-lg font-bold">{title}</h2><button onClick={onClose} aria-label="Close" className="rounded p-1 hover:bg-black/10"><X size={18} /></button></div>
        <div className="flex-1 overflow-y-auto p-5">{children}</div>
      </div>
    </div>
  );
}
export function Pager({ page, pages, total, onPage }: { page: number; pages: number; total: number; onPage: (p: number) => void }) {
  return (<div className="flex items-center justify-between border-t px-3 py-2 text-xs" style={{ borderColor: 'var(--line)' }}><span className="opacity-70">{total.toLocaleString()} record{total === 1 ? '' : 's'}</span>
    <div className="flex items-center gap-1"><button className="btn-ghost !px-2 !py-1" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Previous page"><ChevronLeft size={14} /></button><span>Page {page} / {Math.max(1, pages)}</span><button className="btn-ghost !px-2 !py-1" disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="Next page"><ChevronRight size={14} /></button></div></div>);
}
export const PageHead = ({ title, sub, children }: { title: string; sub?: string; children?: ReactNode }) => (
  <div className="mb-4 flex flex-wrap items-end justify-between gap-3"><div><h1 className="font-head text-2xl font-extrabold">{title}</h1>{sub && <p className="text-sm opacity-60">{sub}</p>}</div><div className="flex flex-wrap items-center gap-2">{children}</div></div>
);

/** Enumerations for known columns (structure only). */
export const ENUMS: Record<string, string[]> = {
  verification_status: ['unverified', 'verified'], status: ['draft', 'open', 'pending_review', 'approved', 'issued', 'rejected', 'closed', 'active', 'inactive'],
  orientation: ['portrait', 'landscape'], po_type: ['purchase', 'work', 'sales'], unit: ['mm', 'inch'], result: ['pass', 'fail', 'pending'],
  property_type: ['chemical', 'mechanical', 'physical'], family: ['pipe', 'fitting', 'flange', 'other'],
  asset_type: ['company_stamp', 'authorized_signature', 'qc_stamp', 'inspection_stamp', 'prepared_by', 'checked_by', 'approved_by'],
};
const FK_LABEL: Record<string, string> = { customers: 'name', suppliers: 'name', manufacturers: 'name', company_profiles: 'company_name', purchase_orders: 'po_number', heat_numbers: 'heat_number', product_categories: 'name', material_grades: 'grade', templates: 'name', dispatch_batches: 'batch_no', engineering_dimensions: 'item_code', purchase_order_items: 'item_description', documents: 'doc_number', template_versions: 'version_no' };

export function FkSelect({ entity, value, onChange }: { entity: string; value: any; onChange: (v: any) => void }) {
  const [opts, setOpts] = useState<Row[]>([]);
  useEffect(() => { list(entity, { per_page: 200, orderby: 'id', order: 'DESC' }).then((r) => setOpts(r.items)).catch(() => setOpts([])); }, [entity]);
  const k = FK_LABEL[entity] || 'id';
  return (<select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}><option value="">— none —</option>{opts.map((o) => <option key={o.id} value={o.id}>{String(o[k] ?? o.id)}{o.description ? '' : ''}</option>)}</select>);
}
export function fkText(entity: string) { return FK_LABEL[entity] || 'id'; }

export function Field({ col, value, onChange, readOnly }: { col: Col; value: any; onChange: (v: any) => void; readOnly?: boolean }) {
  const s = col.sql.toLowerCase(); const n = col.name;
  const common = { disabled: readOnly };
  let input: ReactNode;
  if (col.fk) input = <FkSelect entity={col.fk} value={value} onChange={onChange} />;
  else if (ENUMS[n]) input = <select className="input" {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)}><option value="">—</option>{ENUMS[n].map((o) => <option key={o} value={o}>{label(o)}</option>)}</select>;
  else if (s.startsWith('tinyint')) input = <input type="checkbox" className="h-4 w-4" {...common} checked={!!Number(value)} onChange={(e) => onChange(e.target.checked ? 1 : 0)} />;
  else if (s.startsWith('date') && !s.startsWith('datetime')) input = <input type="date" className="input" {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else if (s.startsWith('datetime')) input = <input type="datetime-local" className="input" {...common} value={(value ?? '').replace(' ', 'T')} onChange={(e) => onChange(e.target.value.replace('T', ' '))} />;
  else if (col.json) input = <textarea className="input font-mono text-xs" rows={4} {...common} value={typeof value === 'string' ? value : value ? JSON.stringify(value, null, 2) : ''} onChange={(e) => { try { onChange(e.target.value ? JSON.parse(e.target.value) : null); } catch { onChange(e.target.value); } }} />;
  else if (s.startsWith('text') || s.startsWith('longtext')) input = <textarea className="input" rows={3} {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else if (s.startsWith('decimal') || s.startsWith('int') || s.startsWith('bigint')) input = <input type="number" step="any" className="input font-mono" {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else input = <input className="input" {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  return <div><label className="lbl">{label(n.replace(/_id$/, ''))}{col.required && <span className="text-err"> *</span>}</label>{input}</div>;
}

export function useDebounced<T>(v: T, ms = 300) { const [d, setD] = useState(v); useEffect(() => { const t = setTimeout(() => setD(v), ms); return () => clearTimeout(t); }, [v, ms]); return d; }
export { api };
