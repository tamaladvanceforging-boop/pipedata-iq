import { ReactNode, useCallback, useEffect, useState } from 'react';
import { Plus, Search, Download, Copy, Archive, RotateCcw, Trash2, Pencil, ArrowUpDown, BadgeCheck, History, Printer } from 'lucide-react';
import { api, archive, create, download, duplicate, label, list, Page, purge, restore, Row, update } from '../api';
import { useApp } from '../ctx';
import { Badge, Drawer, Empty, ErrorBox, Field, fkText, PageHead, Pager, Spinner, useDebounced } from '../ui';

interface Props {
  entity: string; title?: string; sub?: string; filters?: Record<string, any>; defaults?: Row; hideCols?: string[];
  extra?: (row: Row, reload: () => void) => ReactNode; header?: ReactNode; readonlyForm?: boolean; allowVerify?: boolean; versions?: boolean;
}
const NUM = /decimal|int/;

export function EntityPage({ entity, title, sub, filters, defaults, hideCols, extra, header, allowVerify, versions }: Props) {
  const { boot, toast, confirm } = useApp(); const meta = boot.entities[entity];
  const [q, setQ] = useState(''); const dq = useDebounced(q); const [page, setPage] = useState(1);
  const [orderby, setOrderby] = useState('id'); const [order, setOrder] = useState<'ASC' | 'DESC'>('DESC'); const [trashed, setTrashed] = useState(false);
  const [data, setData] = useState<Page | null>(null); const [err, setErr] = useState(''); const [loading, setLoading] = useState(true);
  const [edit, setEdit] = useState<Row | null>(null); const [saving, setSaving] = useState(false); const [errs, setErrs] = useState<string[]>([]);
  const [hist, setHist] = useState<Row[] | null>(null);
  const fkey = JSON.stringify(filters || {});

  const load = useCallback(() => {
    setLoading(true); setErr('');
    list(entity, { page, per_page: 25, search: dq, orderby, order, trashed: trashed ? 1 : '', filters: filters || {} })
      .then(setData).catch((e) => setErr(e.message)).finally(() => setLoading(false));
  }, [entity, page, dq, orderby, order, trashed, fkey]); // eslint-disable-line
  useEffect(() => { load(); }, [load]);
  useEffect(() => { setPage(1); }, [dq, entity, trashed, fkey]);

  if (!meta) return <ErrorBox msg={`Unknown module: ${entity}`} />;
  const cols = meta.list.filter((c) => !(hideCols || []).includes(c));
  const sort = (c: string) => { if (orderby === c) setOrder(order === 'ASC' ? 'DESC' : 'ASC'); else { setOrderby(c); setOrder('ASC'); } };
  const fmt = (c: string, v: any) => {
    if (v === null || v === undefined || v === '') return <span className="opacity-30">—</span>;
    if (/status|result|^status$/.test(c)) return <Badge v={v} />;
    if (typeof v === 'object') return JSON.stringify(v).slice(0, 40);
    if (/^(is_|enabled|mapping_verified)/.test(c)) return Number(v) ? '✓' : '—';
    if (c.endsWith('_id')) return <span className="font-mono text-xs">#{v}</span>;
    const col = meta.columns.find((x) => x.name === c);
    return <span className={col && NUM.test(col.sql) ? 'font-mono' : ''}>{String(v)}</span>;
  };
  const save = async () => {
    if (!edit) return; setSaving(true); setErrs([]);
    try {
      const body: Row = {}; meta.columns.forEach((c) => { if (c.name in edit) body[c.name] = edit[c.name]; });
      if (edit.id) await update(entity, edit.id, body); else await create(entity, { ...defaults, ...body });
      toast('Saved'); setEdit(null); load();
    } catch (e: any) { toast(e.message, 'err'); setErrs([e.message]); } finally { setSaving(false); }
  };
  const act = async (fn: () => Promise<any>, ok: string) => { try { await fn(); toast(ok); load(); } catch (e: any) { toast(e.message, 'err'); } };
  const exportIt = async (format: 'csv' | 'json') => { try { const r = await api('export/' + entity, { query: { format, search: dq, filters: filters || {} } }); download(r.filename, r.content, r.mime); toast(`Exported ${r.count} rows`); } catch (e: any) { toast(e.message, 'err'); } };
  const showHist = async (id: number) => { try { setHist(await api('e/' + entity + '/' + id + '/versions')); } catch (e: any) { toast(e.message, 'err'); } };
  const newRow = () => { const r: Row = { ...defaults }; setEdit(r); setErrs([]); };

  return (
    <div>
      <PageHead title={title || meta.label} sub={sub}>
        <div className="relative"><Search size={14} className="absolute left-2.5 top-3 opacity-50" /><input className="input !pl-8 w-56" placeholder="Search…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search" /></div>
        <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={trashed} onChange={(e) => setTrashed(e.target.checked)} /> Archived</label>
        <button className="btn-ghost" onClick={() => exportIt('csv')}><Download size={14} /> CSV</button>
        <button className="btn-ghost" onClick={() => exportIt('json')}><Download size={14} /> JSON</button>
        {!meta.readonly && <button className="btn-primary" onClick={newRow}><Plus size={14} /> New</button>}
      </PageHead>
      {header}
      <div className="card overflow-hidden">
        {err ? <ErrorBox msg={err} retry={load} /> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b" style={{ borderColor: 'var(--line)' }}><tr>
                {cols.map((c) => <th key={c} className="th"><button className="flex items-center gap-1" onClick={() => sort(c)}>{label(c.replace(/_id$/, ''))}<ArrowUpDown size={11} className={orderby === c ? 'text-brand' : 'opacity-30'} /></button></th>)}
                <th className="th text-right">Actions</th></tr></thead>
              <tbody>
                {loading && !data && <tr><td colSpan={cols.length + 1} className="p-8 text-center"><Spinner /></td></tr>}
                {data?.items.map((r) => (
                  <tr key={r.id} className="border-b last:border-0 hover:bg-black/[.03] dark:hover:bg-white/[.04]" style={{ borderColor: 'var(--line)' }}>
                    {cols.map((c) => <td key={c} className="td max-w-[260px] truncate">{fmt(c, r[c])}</td>)}
                    <td className="td min-w-[210px] !whitespace-normal text-right"><div className="flex flex-wrap justify-end gap-1">
                      {extra?.(r, load)}
                      {allowVerify && r.verification_status !== 'verified' && !trashed && <button className="btn-ghost !px-2 !py-1" title="Mark verified" onClick={() => act(() => update(entity, r.id, { verification_status: 'verified' }), 'Marked verified')}><BadgeCheck size={14} /></button>}
                      {!trashed && !meta.readonly && <button className="btn-ghost !px-2 !py-1" title="Edit" onClick={() => { setEdit({ ...r }); setErrs([]); }}><Pencil size={14} /></button>}
                      {!trashed && versions && <button className="btn-ghost !px-2 !py-1" title="Version history" onClick={() => showHist(r.id)}><History size={14} /></button>}
                      {!trashed && !meta.readonly && <button className="btn-ghost !px-2 !py-1" title="Duplicate" onClick={() => act(() => duplicate(entity, r.id), 'Duplicated')}><Copy size={14} /></button>}
                      {!trashed && !meta.readonly && <button className="btn-ghost !px-2 !py-1" title="Archive" onClick={() => act(() => archive(entity, r.id), 'Archived')}><Archive size={14} /></button>}
                      {trashed && <button className="btn-ghost !px-2 !py-1" title="Restore" onClick={() => act(() => restore(entity, r.id), 'Restored')}><RotateCcw size={14} /></button>}
                      {trashed && <button className="btn-ghost !px-2 !py-1 text-err" title="Delete permanently" onClick={async () => { if (await confirm('Permanently delete this record? This cannot be undone.')) act(() => purge(entity, r.id), 'Deleted'); }}><Trash2 size={14} /></button>}
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data && data.items.length === 0 && <Empty title={trashed ? 'No archived records' : `No ${meta.label.toLowerCase()} yet`} hint={q ? 'Try a different search.' : meta.readonly ? undefined : 'Click “New” to add one, or use Import / Export to bulk-load data.'} />}
          </div>
        )}
        {data && <Pager page={data.page} pages={data.pages} total={data.total} onPage={setPage} />}
      </div>

      {edit && (
        <Drawer title={(edit.id ? 'Edit ' : 'New ') + meta.label.replace(/s$/, '')} onClose={() => setEdit(null)} wide>
          {errs.length > 0 && <div className="mb-3 rounded-lg border border-err/40 bg-err/10 p-3 text-err">{errs[0]}</div>}
          <div className="grid gap-3 sm:grid-cols-2">
            {meta.columns.filter((c) => !(c.name in (defaults || {})) || edit.id).map((c) => (
              <div key={c.name} className={/text|json/.test(c.sql) || c.json ? 'sm:col-span-2' : ''}><Field col={c} value={edit[c.name]} onChange={(v) => setEdit((p) => ({ ...p, [c.name]: v }))} /></div>
            ))}
          </div>
          <div className="mt-5 flex justify-end gap-2"><button className="btn-ghost" onClick={() => setEdit(null)}>Cancel</button><button className="btn-primary" disabled={saving} onClick={save}>{saving && <Spinner />} Save</button></div>
        </Drawer>
      )}
      {hist && (
        <Drawer title="Version history" onClose={() => setHist(null)} wide>
          {hist.length === 0 ? <Empty title="No versions" /> : hist.map((h) => (
            <div key={h.id} className="card mb-3 p-3"><div className="mb-2 flex justify-between text-xs"><b>Version {h.version_no}</b><span className="opacity-60">{h.created_at}</span></div>
              <pre className="max-h-48 overflow-auto rounded bg-black/5 p-2 font-mono text-[11px]">{JSON.stringify(h.snapshot, null, 1)}</pre></div>))}
        </Drawer>
      )}
    </div>
  );
}
export { fkText, Printer };
