import { ReactNode, useState } from 'react';
import { ClipboardList, Copy, FlaskConical, ListChecks, PackageOpen, Scale, Paperclip } from 'lucide-react';
import { api, duplicate, Row, update, list } from '../api';
import { useApp } from '../ctx';
import { Drawer, Spinner } from '../ui';
import { EntityPage } from './EntityPage';

function Nested({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) { return <Drawer title={title} onClose={onClose} wide><div>{children}</div></Drawer>; }

export function PurchaseOrders() {
  const { toast } = useApp(); const [items, setItems] = useState<Row | null>(null); const [bal, setBal] = useState<Row[] | null>(null);
  return (<>
    <EntityPage entity="purchase_orders" title="Purchase Orders" defaults={{ po_type: 'purchase' }} filters={{ po_type: 'purchase' }}
      extra={(r) => (<>
        <button className="btn-ghost !px-2 !py-1" title="Order items" onClick={() => setItems(r)}><ClipboardList size={14} /></button>
        <button className="btn-ghost !px-2 !py-1" title="Balance quantities" onClick={async () => { try { setBal(await api('po/' + r.id + '/balance')); } catch (e: any) { toast(e.message, 'err'); } }}><Scale size={14} /></button></>)} />
    {items && <Nested title={`Items — PO ${items.po_number}`} onClose={() => setItems(null)}><EntityPage entity="purchase_order_items" defaults={{ po_id: items.id }} filters={{ po_id: items.id }} hideCols={[]} /></Nested>}
    {bal && <Nested title="PO balance quantities" onClose={() => setBal(null)}>
      <table className="w-full text-sm"><thead><tr><th className="th">Line</th><th className="th">Item</th><th className="th">Ordered</th><th className="th">Dispatched</th><th className="th">Balance</th></tr></thead>
        <tbody>{bal.map((b) => <tr key={b.id} className="border-t" style={{ borderColor: 'var(--line)' }}><td className="td">{b.line_no}</td><td className="td max-w-[220px] truncate">{b.item_description}</td><td className="td font-mono">{b.ordered_qty}</td><td className="td font-mono">{b.dispatched_qty}</td><td className={`td font-mono ${b.balance.over_dispatched ? 'text-err' : ''}`}>{b.balance.balance}{b.balance.over_dispatched ? ' ⚠ over-dispatched' : ''}</td></tr>)}</tbody></table>
      {bal.length === 0 && <p className="p-4 text-sm opacity-60">No items on this order.</p>}</Nested>}
  </>);
}

export function HeatNumbers() {
  const { toast } = useApp(); const [tests, setTests] = useState<Row | null>(null); const [dup, setDup] = useState<Row | null>(null); const [ov, setOv] = useState<Row>({}); const [busy, setBusy] = useState(false);
  const attach = async (r: Row, f: File | undefined) => { if (!f) return; const fd = new FormData(); fd.append('file', f); try { await api(`assets/heat_numbers/${r.id}/mtc_path`, { method: 'POST', form: fd }); toast('Mill test certificate attached'); } catch (e: any) { toast(e.message, 'err'); } };
  return (<>
    <EntityPage entity="heat_numbers" title="Heat Number Management" sub="Traceability — documents can only be issued from verified heats" allowVerify
      extra={(r) => (<>
        <button className="btn-ghost !px-2 !py-1" title="Chemical & mechanical tests" onClick={() => setTests(r)}><FlaskConical size={14} /></button>
        <label className="btn-ghost !px-2 !py-1 cursor-pointer" title="Attach original MTC (PDF/image)"><Paperclip size={14} /><input type="file" className="hidden" accept=".pdf,.png,.jpg,.jpeg" onChange={(e) => attach(r, e.target.files?.[0])} /></label>
        <button className="btn-ghost !px-2 !py-1" title="Duplicate with new heat/qty/PO/date" onClick={() => { setDup(r); setOv({ heat_number: '', quantity: r.quantity, po_id: r.po_id, inspection_date: r.inspection_date }); }}><Copy size={14} /></button></>)} />
    {dup && <Drawer title={`Duplicate heat ${dup.heat_number}`} onClose={() => setDup(null)}>
      <p className="mb-3 rounded-lg border border-warn/40 bg-warn/10 p-3 text-xs">The copy is created as <b>unverified</b>. Heat-specific chemical/mechanical results must be re-entered and verified before any final document can be issued.</p>
      <div className="grid gap-3"><div><label className="lbl">New heat number *</label><input className="input font-mono" value={ov.heat_number || ''} onChange={(e) => setOv({ ...ov, heat_number: e.target.value })} /></div>
        <div><label className="lbl">Quantity</label><input className="input font-mono" type="number" value={ov.quantity ?? ''} onChange={(e) => setOv({ ...ov, quantity: e.target.value })} /></div>
        <div><label className="lbl">Inspection date</label><input className="input" type="date" value={ov.inspection_date ?? ''} onChange={(e) => setOv({ ...ov, inspection_date: e.target.value })} /></div>
        <div><label className="lbl">PO ID</label><input className="input font-mono" type="number" value={ov.po_id ?? ''} onChange={(e) => setOv({ ...ov, po_id: e.target.value })} /></div></div>
      <div className="mt-4 flex justify-end gap-2"><button className="btn-ghost" onClick={() => setDup(null)}>Cancel</button><button className="btn-primary" disabled={busy || !ov.heat_number} onClick={async () => { setBusy(true); try { await duplicate('heat_numbers', dup.id, ov); toast('Duplicated as unverified'); setDup(null); location.reload(); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } }}>{busy && <Spinner />} Create copy</button></div></Drawer>}
    {tests && <Nested title={`Test results — heat ${tests.heat_number}`} onClose={() => setTests(null)}>
      <h3 className="mb-2 font-head font-bold">Chemical composition</h3><EvalPage entity="chemical_tests" heat={tests} />
      <h3 className="mb-2 mt-6 font-head font-bold">Mechanical properties</h3><EvalPage entity="mechanical_tests" heat={tests} /></Nested>}
  </>);
}

/** Test rows with a deterministic limit check button (result derived, never guessed). */
function EvalPage({ entity, heat }: { entity: string; heat: Row }) {
  const { toast } = useApp();
  return <EntityPage entity={entity} defaults={{ heat_id: heat.id }} filters={{ heat_id: heat.id }} hideCols={['heat_id']}
    extra={(r, reload) => (<button className="btn-ghost !px-2 !py-1" title="Check against limits" onClick={async () => { try { const x = await api('calc/limits', { method: 'POST', body: { actual: r.actual_value, min: r.min_limit, max: r.max_limit } }); await update(entity, r.id, { result: x.result }); toast('Result: ' + x.result); reload(); } catch (e: any) { toast(e.message, 'err'); } }}><ListChecks size={14} /></button>)} />;
}

export function Inspection() {
  const { toast } = useApp();
  return <EntityPage entity="inspection_results" title="Quality Inspection" sub="Dimensional / visual / other inspection records — enter actual measured values only"
    extra={(r, reload) => (<button className="btn-ghost !px-2 !py-1" title="Evaluate tolerance" onClick={async () => { try { const x = await api('calc/tolerance', { method: 'POST', body: { nominal: r.nominal_value, actual: r.actual_value, tol_min: r.tol_min, tol_max: r.tol_max } }); await update('inspection_results', r.id, { result: x.result }); toast(x.result === 'pending' ? 'Pending — enter actual value and tolerance' : 'Result: ' + x.result, x.result === 'fail' ? 'err' : 'ok'); reload(); } catch (e: any) { toast(e.message, 'err'); } }}><ListChecks size={14} /></button>)} />;
}

export function Dispatch() {
  const [items, setItems] = useState<Row | null>(null);
  return (<>
    <EntityPage entity="dispatch_batches" title="Dispatch Management" sub="One PO can have many dispatch batches" extra={(r) => <button className="btn-ghost !px-2 !py-1" title="Batch items" onClick={() => setItems(r)}><PackageOpen size={14} /></button>} />
    {items && <Nested title={`Batch ${items.batch_no} — items`} onClose={() => setItems(null)}><EntityPage entity="dispatch_items" defaults={{ batch_id: items.id }} filters={{ batch_id: items.id }} hideCols={['batch_id']} /></Nested>}
  </>);
}
export { list };
