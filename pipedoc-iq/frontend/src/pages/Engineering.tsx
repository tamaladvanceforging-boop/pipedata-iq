import { useState } from 'react';
import { Calculator, Sparkles, Copy } from 'lucide-react';
import { api, Row } from '../api';
import { useApp } from '../ctx';
import { Badge, Spinner } from '../ui';
import { EntityPage } from './EntityPage';

function Assistant() {
  const { toast } = useApp(); const [q, setQ] = useState(''); const [res, setRes] = useState<Row | null>(null); const [busy, setBusy] = useState(false);
  const [od, setOd] = useState(''); const [wt, setWt] = useState(''); const [calc, setCalc] = useState<Row | null>(null);
  const run = async () => { if (!q.trim()) return; setBusy(true); try { setRes(await api('search/items', { query: { q } })); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } };
  const doCalc = async () => { try { const [i, w] = await Promise.all([api('calc/inside_diameter', { method: 'POST', body: { od, wt } }), api('calc/weight_per_meter', { method: 'POST', body: { od, wt } })]); setCalc({ id: i.value, w: w.value }); } catch (e: any) { toast(e.message, 'err'); } };
  const p = res?.parsed;
  return (
    <div className="card mb-4 grid gap-4 p-4 lg:grid-cols-3">
      <div className="lg:col-span-2">
        <div className="mb-1 flex items-center gap-1 font-head font-bold"><Sparkles size={15} className="text-brand" /> Smart description search <span className="text-xs font-normal opacity-60">(works offline, no AI key needed)</span></div>
        <div className="flex gap-2"><input className="input font-mono" placeholder="e.g. 100NB CL150 FLANGE WELDNECK RAISED FACE FLANGE (WNRF)" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && run()} /><button className="btn-primary" onClick={run} disabled={busy}>{busy ? <Spinner /> : 'Find'}</button></div>
        {p && (<div className="mt-3 text-sm"><div className="flex flex-wrap gap-2">{[['Product', p.product], ['Nominal size', p.nb ? `${p.nb} NB${p.nps ? ' / NPS ' + p.nps : ''}` : p.nps ? 'NPS ' + p.nps : null], ['Class', p.pressure_class], ['Facing', p.facing], ['Abbr.', p.abbreviation], ['Schedule', p.schedule], ['Grade', p.material_grade]].filter((x) => x[1]).map(([k, v]) => <span key={k as string} className="rounded-full border px-2 py-0.5 text-xs" style={{ borderColor: 'var(--line)' }}><span className="opacity-60">{k}: </span><b className="font-mono">{v}</b></span>)}</div>
          {res.needs_confirmation && <div className="mt-2 rounded-lg border border-warn/50 bg-warn/10 p-2 text-xs">Multiple variants match (differ in: {res.ambiguous_on.join(', ')}). Please confirm the correct record before using it.</div>}
          {res.matches.length === 0 ? <p className="mt-2 text-xs opacity-70">No matching records in your engineering database. Import data or add the record — values are never guessed.</p> :
            <div className="mt-2 max-h-48 overflow-auto rounded-lg border" style={{ borderColor: 'var(--line)' }}>{res.matches.map((m: Row) => (<div key={m.id} className="flex items-center justify-between border-b px-3 py-1.5 text-xs last:border-0" style={{ borderColor: 'var(--line)' }}><span className="font-mono">{m.item_code}</span><span className="truncate px-2">{m.item_type || m.category} · NPS {m.nps} · {m.schedule || m.pressure_class} · {m.material_grade}</span><Badge v={m.verification_status} /></div>))}</div>}
        </div>)}
      </div>
      <div><div className="mb-1 flex items-center gap-1 font-head font-bold"><Calculator size={15} className="text-brand" /> Pipe calculator</div>
        <div className="grid grid-cols-2 gap-2"><div><label className="lbl">OD (mm)</label><input className="input font-mono" value={od} onChange={(e) => setOd(e.target.value)} /></div><div><label className="lbl">Wall (mm)</label><input className="input font-mono" value={wt} onChange={(e) => setWt(e.target.value)} /></div></div>
        <button className="btn-ghost mt-2" onClick={doCalc}>Calculate</button>
        {calc && <div className="mt-2 text-xs"><div>Inside dia: <b className="font-mono">{calc.id ?? 'n/a'}</b> mm <i className="opacity-60">(calculated)</i></div><div>Weight: <b className="font-mono">{calc.w ?? 'n/a'}</b> kg/m <i className="opacity-60">(approx., steel 7850 kg/m³)</i></div></div>}</div>
    </div>
  );
}
export function Engineering() {
  const { toast } = useApp(); const [dups, setDups] = useState<Row[] | null>(null);
  return (
    <EntityPage entity="engineering_dimensions" title="Engineering Database" sub="Verified engineering dimensions — never auto-generated" allowVerify versions
      header={<><Assistant /><div className="mb-3 flex items-center gap-2"><button className="btn-ghost" onClick={async () => { try { setDups(await api('engineering/duplicates')); } catch (e: any) { toast(e.message, 'err'); } }}><Copy size={14} /> Detect duplicates</button>{dups && <span className="text-sm">{dups.length === 0 ? 'No duplicate groups found.' : `${dups.length} duplicate group(s): record IDs ${dups.map((d) => d.ids).join(' | ')}`}</span>}</div></>} />
  );
}
