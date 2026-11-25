import { useCallback, useEffect, useRef, useState } from 'react';
import { FileCog, Upload, Sparkles, Save, BadgeCheck, AlertTriangle, Stamp } from 'lucide-react';
import { api, list, Row, update, rest } from '../api';
import { useApp } from '../ctx';
import { Badge, Drawer, PageHead, Spinner } from '../ui';
import { EntityPage } from './EntityPage';

function UploadBox({ templateId, onDone }: { templateId?: number; onDone: (r: Row) => void }) {
  const { boot, toast } = useApp(); const [file, setFile] = useState<File | null>(null); const [name, setName] = useState(''); const [dt, setDt] = useState('Test Certificate (TC)'); const [busy, setBusy] = useState(false);
  const all = [...boot.doc_types.quality, ...boot.doc_types.dispatch];
  const go = async () => { if (!file) return; setBusy(true); const fd = new FormData(); fd.append('file', file); if (templateId) fd.append('template_id', String(templateId)); else { fd.append('name', name || file.name.replace(/\.\w+$/, '')); fd.append('doc_type', dt); } try { const r = await api('templates/upload', { method: 'POST', form: fd }); toast('Template analysed'); onDone(r); setFile(null); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } };
  return (
    <div className="card mb-4 p-4"><h3 className="mb-2 font-head font-bold">{templateId ? 'Upload new version' : 'Upload a Word / Excel report format'}</h3>
      <div className="grid gap-3 sm:grid-cols-3">
        {!templateId && <><div><label className="lbl">Template name</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Customer A — Test Certificate" /></div>
          <div><label className="lbl">Document type (pick or type a custom one)</label><input className="input" list="pdq-doc-types" value={dt} onChange={(e) => setDt(e.target.value)} /><datalist id="pdq-doc-types">{all.map((d) => <option key={d} value={d} />)}</datalist></div></>}
        <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center text-sm hover:border-brand" style={{ borderColor: 'var(--line)' }} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); setFile(e.dataTransfer.files[0] || null); }}>
          <Upload size={16} /> {file ? file.name : 'Drop DOCX / XLSX / XLSM / PDF'}<input type="file" className="hidden" accept=".docx,.xlsx,.xlsm,.pdf,.doc,.xls" onChange={(e) => setFile(e.target.files?.[0] || null)} /></label>
      </div>
      <button className="btn-primary mt-3" disabled={!file || busy} onClick={go}>{busy && <Spinner />} Upload & analyse</button>
      <p className="mt-2 text-xs opacity-60">Macros in XLSM files are never executed and are removed from generated output. Add placeholders like <code className="font-mono">{'{{company_name}}'}</code> in your file; table rows containing item placeholders repeat per item.</p></div>
  );
}

/** Fetch a private asset with the REST nonce and expose it as a blob URL. */
function useAsset(entity: string, id?: number, col?: string) {
  const [url, setUrl] = useState('');
  useEffect(() => { if (!id || !col) { setUrl(''); return; } let u = ''; fetch(rest(`assets/${entity}/${id}/${col}`), { headers: { 'X-WP-Nonce': window.PDQ.nonce } }).then((r) => (r.ok ? r.blob() : Promise.reject())).then((b) => { u = URL.createObjectURL(b); setUrl(u); }).catch(() => setUrl('')); return () => { if (u) URL.revokeObjectURL(u); }; }, [entity, id, col]);
  return url;
}

interface Placement { key: string; col: string; x: number; y: number; w: number; rot: number; opacity: number; page: number }
const KINDS: [string, string][] = [['stamp_path', 'Company stamp'], ['signature_path', 'Authorized signature'], ['qc_stamp_path', 'QC stamp'], ['inspection_stamp_path', 'Inspection stamp']];

function Positioner({ version, onSaved }: { version: Row; onSaved: () => void }) {
  const { toast } = useApp(); const [companies, setCompanies] = useState<Row[]>([]); const [cid, setCid] = useState<number>(); const [pl, setPl] = useState<Placement[]>(version.page_settings?.placements || []); const [sel, setSel] = useState(0); const [page, setPage] = useState(1);
  const box = useRef<HTMLDivElement>(null); const drag = useRef<{ i: number; dx: number; dy: number } | null>(null);
  useEffect(() => { list('company_profiles', { per_page: 100 }).then((r) => { setCompanies(r.items); if (r.items[0]) setCid(r.items[0].id); }); }, []);
  const cur = pl[sel]; const imgUrl = useAsset('company_profiles', cid, cur?.col);
  const add = (col: string) => { setPl([...pl, { key: col + Date.now(), col, x: 60, y: 80, w: 25, rot: 0, opacity: 0.9, page }]); setSel(pl.length); };
  const set = (patch: Partial<Placement>) => setPl(pl.map((p, i) => (i === sel ? { ...p, ...patch } : p)));
  const onMove = (e: React.PointerEvent) => { if (!drag.current || !box.current) return; const r = box.current.getBoundingClientRect(); const x = ((e.clientX - r.left) / r.width) * 100 - drag.current.dx; const y = ((e.clientY - r.top) / r.height) * 100 - drag.current.dy; setPl((p) => p.map((q, i) => (i === drag.current!.i ? { ...q, x: Math.max(0, Math.min(95, x)), y: Math.max(0, Math.min(95, y)) } : q))); };
  const save = async () => { try { await update('template_versions', version.id, { page_settings: { ...(version.page_settings || {}), placements: pl } }); toast('Positions saved for this template version'); onSaved(); } catch (e: any) { toast(e.message, 'err'); } };
  return (
    <div className="card mt-4 p-4"><h3 className="mb-1 flex items-center gap-2 font-head font-bold"><Stamp size={16} className="text-brand" /> Stamp & signature positions</h3>
      <p className="mb-3 text-xs opacity-60">Positions are stored per template version. Image-based signatures are visual only and are <b>not</b> cryptographic digital signatures. They are applied to final documents only after your explicit approval.</p>
      <div className="grid gap-4 lg:grid-cols-[auto_1fr]">
        <div><div ref={box} className="relative select-none overflow-hidden border bg-white shadow" style={{ width: 300, height: 424, borderColor: 'var(--line)' }} onPointerMove={onMove} onPointerUp={() => (drag.current = null)} onPointerLeave={() => (drag.current = null)}>
          <div className="absolute inset-3 border border-dashed border-gray-300" /><div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-xs text-gray-300">A4 · page {page}</div>
          {pl.map((p, i) => p.page === page && <div key={p.key} onPointerDown={(e) => { setSel(i); const r = box.current!.getBoundingClientRect(); drag.current = { i, dx: ((e.clientX - r.left) / r.width) * 100 - p.x, dy: ((e.clientY - r.top) / r.height) * 100 - p.y }; (e.target as HTMLElement).setPointerCapture?.(e.pointerId); }}
            className={`absolute cursor-move ${i === sel ? 'outline outline-2 outline-brand' : 'outline outline-1 outline-gray-300'}`} style={{ left: `${p.x}%`, top: `${p.y}%`, width: `${p.w}%`, opacity: p.opacity, transform: `rotate(${p.rot}deg)` }}>
            {i === sel && imgUrl ? <img src={imgUrl} alt="" draggable={false} className="w-full" /> : <div className="flex aspect-square items-center justify-center bg-orange-100 text-[9px] text-orange-700">{KINDS.find((k) => k[0] === p.col)?.[1]}</div>}</div>)}
        </div></div>
        <div className="space-y-3 text-sm">
          <div className="flex flex-wrap items-end gap-2"><div><label className="lbl">Company</label><select className="input" value={cid || ''} onChange={(e) => setCid(Number(e.target.value))}>{companies.map((c) => <option key={c.id} value={c.id}>{c.company_name}</option>)}</select></div>
            <div><label className="lbl">Page</label><input type="number" min={1} className="input !w-20" value={page} onChange={(e) => setPage(Math.max(1, Number(e.target.value)))} /></div></div>
          <div className="flex flex-wrap gap-1">{KINDS.map(([c, l]) => <button key={c} className="btn-ghost !py-1 text-xs" onClick={() => add(c)}>+ {l}</button>)}</div>
          {cur ? <div className="grid grid-cols-2 gap-3"><div><label className="lbl">Size ({cur.w.toFixed(0)}% width)</label><input type="range" min={5} max={60} value={cur.w} onChange={(e) => set({ w: Number(e.target.value) })} className="w-full" /></div>
            <div><label className="lbl">Rotate ({cur.rot}°)</label><input type="range" min={-180} max={180} value={cur.rot} onChange={(e) => set({ rot: Number(e.target.value) })} className="w-full" /></div>
            <div><label className="lbl">Opacity ({Math.round(cur.opacity * 100)}%)</label><input type="range" min={10} max={100} value={cur.opacity * 100} onChange={(e) => set({ opacity: Number(e.target.value) / 100 })} className="w-full" /></div>
            <div><label className="lbl">Page</label><input type="number" min={1} className="input" value={cur.page} onChange={(e) => set({ page: Math.max(1, Number(e.target.value)) })} /></div>
            <div className="col-span-2 flex gap-2"><span className="text-xs opacity-60">X {cur.x.toFixed(1)}% · Y {cur.y.toFixed(1)}%</span><button className="ml-auto text-xs text-err underline" onClick={() => { setPl(pl.filter((_, i) => i !== sel)); setSel(0); }}>Remove</button></div></div> : <p className="text-xs opacity-60">Add an item, then drag it on the page.</p>}
          <button className="btn-primary" onClick={save}><Save size={14} /> Save positions</button></div>
      </div></div>
  );
}

function Studio({ tpl, onClose }: { tpl: Row; onClose: () => void }) {
  const { boot, toast } = useApp(); const [versions, setVersions] = useState<Row[]>([]); const [v, setV] = useState<Row | null>(null); const [maps, setMaps] = useState<Row[]>([]); const [busy, setBusy] = useState('');
  const [verified, setVerified] = useState(false);
  const loadV = useCallback(async (keep?: number) => { const r = await api('templates/' + tpl.id + '/versions'); setVersions(r.items); const pick = r.items.find((x: Row) => x.id === keep) || r.items[0]; if (pick) openV(pick); }, [tpl.id]); // eslint-disable-line
  const openV = async (ver: Row) => { setV(ver); setVerified(!!Number(ver.mapping_verified)); const m = await api('templates/version/' + ver.id + '/mappings'); const have = m.items as Row[]; const det = (ver.analysis?.placeholders || []) as Row[]; const extra = det.filter((p) => !have.find((h) => h.placeholder === p.name)).map((p) => ({ placeholder: p.name, location: (p.locations || []).join(', '), data_source: p.known ? p.name : '', is_repeating: 0, mapped_by: 'manual', _new: true })); setMaps([...have, ...extra]); };
  useEffect(() => { loadV(); }, [loadV]);
  const save = async (ver: boolean) => { if (!v) return; setBusy('save'); try { await api(`templates/version/${v.id}/mappings`, { method: 'POST', body: { mappings: maps.map(({ _new, id, ...r }) => r), verified: ver } }); toast(ver ? 'Mapping saved & verified — reusable without remapping' : 'Mapping saved'); setVerified(ver); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(''); } };
  const ai = async () => { if (!v) return; setBusy('ai'); try { const r = await api(`templates/version/${v.id}/ai-suggest`, { method: 'POST' }); const sugg = (r.mappings || []).filter((m: Row) => m.placeholder && boot.placeholders.includes(m.placeholder)); setMaps((cur) => [...cur, ...sugg.map((s: Row) => ({ placeholder: s.placeholder, location: s.location, data_source: s.placeholder, is_repeating: 0, mapped_by: 'ai', _new: true }))]); toast(`${sugg.length} AI suggestion(s) added — review before saving`, 'info'); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(''); } };
  const a = v?.analysis || {};
  return (
    <Drawer title={`Template Studio — ${tpl.name}`} onClose={onClose} wide>
      <UploadBox templateId={tpl.id} onDone={(r) => loadV(r.version.id)} />
      <div className="mb-3 flex flex-wrap items-center gap-2 text-sm"><span className="opacity-60">Version:</span>{versions.map((x) => <button key={x.id} onClick={() => openV(x)} className={`rounded-full px-3 py-0.5 text-xs ${v?.id === x.id ? 'bg-brand text-white' : 'btn-ghost !py-0.5'}`}>v{x.version_no}{Number(x.mapping_verified) ? ' ✓' : ''}</button>)}</div>
      {v && (<>
        <div className="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">{[['Format', (a.format || '').toUpperCase()], ['Tables', a.tables], ['Merged cells', a.merged_cells], ['Images', a.images], ['Headers/footers', a.headers_footers], ['Orientation', a.page?.orientation], ['Sheets', (a.sheets || []).length || '—'], ['Named ranges', (a.named_ranges || []).length || '—']].map(([k, val]) => <div key={k as string} className="card p-2"><div className="opacity-60">{k}</div><div className="font-mono font-bold">{String(val ?? '—')}</div></div>)}</div>
        {(a.warnings || []).map((w: string, i: number) => <div key={i} className="mt-2 flex gap-2 rounded-lg border border-warn/40 bg-warn/10 p-2 text-xs"><AlertTriangle size={14} className="shrink-0 text-warn" />{w}</div>)}
        <div className="mt-4 flex items-center justify-between"><h3 className="font-head font-bold">Field mapping <Badge v={verified ? 'verified' : 'draft'} /></h3><div className="flex gap-2"><button className="btn-ghost" onClick={ai} disabled={!!busy}>{busy === 'ai' ? <Spinner /> : <Sparkles size={14} />} AI-assisted mapping</button><button className="btn-ghost" onClick={() => setMaps([...maps, { placeholder: '', location: '', data_source: '', is_repeating: 0, mapped_by: 'manual', _new: true }])}>+ Add</button></div></div>
        <div className="mt-2 overflow-x-auto rounded-lg border" style={{ borderColor: 'var(--line)' }}><table className="w-full text-xs"><thead><tr><th className="th">Placeholder</th><th className="th">Found at</th><th className="th">Database field</th><th className="th">Repeats</th><th className="th">By</th><th /></tr></thead>
          <tbody>{maps.map((m, i) => (<tr key={i} className="border-t" style={{ borderColor: 'var(--line)' }}>
            <td className="td"><input className="input !py-1 font-mono" value={m.placeholder} onChange={(e) => setMaps(maps.map((x, j) => (j === i ? { ...x, placeholder: e.target.value } : x)))} /></td><td className="td max-w-[160px] truncate opacity-60">{m.location}</td>
            <td className="td"><input className="input !py-1 font-mono" list="pdq-ph" value={m.data_source || ''} onChange={(e) => setMaps(maps.map((x, j) => (j === i ? { ...x, data_source: e.target.value } : x)))} /></td>
            <td className="td"><input type="checkbox" checked={!!Number(m.is_repeating)} onChange={(e) => setMaps(maps.map((x, j) => (j === i ? { ...x, is_repeating: e.target.checked ? 1 : 0 } : x)))} /></td><td className="td"><Badge v={m.mapped_by === 'ai' ? 'pending' : 'draft'} /></td>
            <td className="td"><button className="text-err" onClick={() => setMaps(maps.filter((_, j) => j !== i))}>✕</button></td></tr>))}</tbody></table>
          {maps.length === 0 && <p className="p-4 text-center text-xs opacity-60">No placeholders detected. Add {'{{placeholders}}'} to your Word/Excel file and upload a new version, or map manually.</p>}</div>
        <datalist id="pdq-ph">{boot.placeholders.map((p) => <option key={p} value={p} />)}</datalist>
        <div className="mt-3 flex gap-2"><button className="btn-ghost" disabled={!!busy} onClick={() => save(false)}><Save size={14} /> Save mapping</button><button className="btn-primary" disabled={!!busy} onClick={() => save(true)}>{busy === 'save' ? <Spinner /> : <BadgeCheck size={14} />} Save & mark verified</button></div>
        <Positioner key={v.id} version={v} onSaved={() => loadV(v.id)} />
      </>)}
    </Drawer>
  );
}

export function Templates() {
  const [studio, setStudio] = useState<Row | null>(null); const [n, setN] = useState(0);
  return (<div>
    <PageHead title="Template Studio" sub="Turn your existing Word / Excel formats into reusable automation templates" />
    <UploadBox onDone={(r) => { setN(n + 1); setStudio(r.template); }} />
    <EntityPage key={n} entity="templates" title="Templates" extra={(r) => <button className="btn-ghost !px-2 !py-1" title="Open studio" onClick={() => setStudio(r)}><FileCog size={14} /></button>} />
    {studio && <Studio tpl={studio} onClose={() => { setStudio(null); setN(n + 1); }} />}
  </div>);
}
