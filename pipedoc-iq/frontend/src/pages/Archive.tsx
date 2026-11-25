import { useState } from 'react';
import { Download, Eye, FileCheck2, GitBranch, Printer, Archive as ArchiveIcon } from 'lucide-react';
import { api, Row, update, rest } from '../api';
import { useApp } from '../ctx';
import { Drawer } from '../ui';
import { EntityPage } from './EntityPage';

export async function downloadDoc(d: Row, fmt: 'native' | 'pdf') {
  const r = await fetch(rest(`documents/${d.id}/file`, fmt === 'pdf' ? 'format=pdf' : ''), { headers: { 'X-WP-Nonce': window.PDQ.nonce } });
  if (!r.ok) { let m = 'Download failed'; try { m = (await r.json()).message || m; } catch { /* ignore */ } throw new Error(m); }
  const b = await r.blob(); const cd = r.headers.get('Content-Disposition') || ''; const name = /filename="?([^";]+)/.exec(cd)?.[1] || 'document';
  const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}
export async function downloadZip(ids: number[]) {
  const r = await fetch(rest('documents/zip', 'ids=' + ids.join(',')), { headers: { 'X-WP-Nonce': window.PDQ.nonce } });
  if (!r.ok) throw new Error('ZIP failed'); const b = await r.blob(); const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = 'pipedoc-iq-documents.zip'; a.click();
}

function Preview({ doc, onClose }: { doc: Row; onClose: () => void }) {
  const s = doc.data_snapshot || {}; const v = s.values || {}; const items: Row[] = s.items || [];
  return (
    <Drawer title={`Preview — ${doc.doc_number} (rev ${doc.revision})`} onClose={onClose} wide>
      <style>{`@media print { body * { visibility: hidden } #pdq-a4, #pdq-a4 * { visibility: visible } #pdq-a4 { position: absolute; left: 0; top: 0; box-shadow: none !important } @page { size: A4; margin: 0 } }`}</style>
      <button className="btn-primary mb-3" onClick={() => window.print()}><Printer size={14} /> Print (A4)</button>
      <div id="pdq-a4" className="mx-auto bg-white p-[14mm] text-black shadow-lg" style={{ width: '210mm', minHeight: '297mm', fontSize: 12 }}>
        <div className="border-b-2 border-black pb-2 text-center"><div className="text-lg font-bold">{v.company_name}</div><div className="text-[10px]">{v.company_address}</div></div>
        <div className="my-3 text-center text-base font-bold uppercase">{doc.doc_type}</div>
        <div className="mb-3 flex justify-between text-xs"><span>No: <b>{doc.doc_number}</b> (Rev {doc.revision})</span><span>Date: {v.certificate_date}</span></div>
        <table className="mb-3 w-full border-collapse text-xs"><tbody>{[['Customer', v.customer_name], ['PO No.', v.po_number], ['PO Date', v.po_date], ['Heat No.', v.heat_number], ['Material grade', v.material_grade]].filter((r) => r[1]).map(([k, val]) => <tr key={k as string}><td className="border border-black p-1 font-semibold" style={{ width: '30%' }}>{k}</td><td className="border border-black p-1">{String(val)}</td></tr>)}</tbody></table>
        {items.length > 0 && <table className="mb-3 w-full border-collapse text-xs"><thead><tr>{['Sr', 'Description', 'Heat', 'Qty'].map((h) => <th key={h} className="border border-black p-1">{h}</th>)}</tr></thead><tbody>{items.map((it, i) => <tr key={i}><td className="border border-black p-1">{i + 1}</td><td className="border border-black p-1">{it.item_description}</td><td className="border border-black p-1">{it.heat_number}</td><td className="border border-black p-1">{it.quantity}</td></tr>)}</tbody></table>}
        {v.chemical_composition && <p className="mb-1 text-xs"><b>Chemical composition:</b> {v.chemical_composition}</p>}{v.mechanical_properties && <p className="mb-1 text-xs"><b>Mechanical properties:</b> {v.mechanical_properties}</p>}
        {doc.status !== 'issued' && <p className="mt-6 text-center text-xs font-bold uppercase text-red-600">Draft — not issued</p>}
        <div className="mt-16 text-right text-xs">For {v.company_name}<br /><br />{v.signatory_name}<br />{v.signatory_designation}</div>
      </div>
      <p className="mt-3 text-center text-[11px] opacity-60">Quick preview from the immutable data snapshot. The downloaded DOCX/XLSX keeps your template’s exact layout.</p>
    </Drawer>
  );
}

export function Archive({ docType, title }: { docType?: string; title?: string }) {
  const { toast, confirm } = useApp(); const [pv, setPv] = useState<Row | null>(null);
  const go = (fn: () => Promise<any>, ok: string, reload: () => void) => fn().then(() => { toast(ok); reload(); }).catch((e) => toast(e.message, 'err'));
  return (<>
    <EntityPage entity="documents" title={title || 'Document Archive'} sub="Issued documents are immutable; changes create a new revision" filters={docType ? { doc_type: docType } : undefined} hideCols={['created_at']}
      extra={(r, reload) => (<>
        <button className="btn-ghost !px-2 !py-1" title="Preview / print" onClick={() => setPv(r)}><Eye size={14} /></button>
        {r.file_path && <button className="btn-ghost !px-2 !py-1" title="Download" onClick={() => downloadDoc(r, 'native').catch((e) => toast(e.message, 'err'))}><Download size={14} /></button>}
        <button className="btn-ghost !px-2 !py-1" title="Create revision" onClick={() => go(() => api(`documents/${r.id}/revise`, { method: 'POST', body: { note: 'Revised' } }), 'New revision created', reload)}><GitBranch size={14} /></button>
        {r.status === 'draft' && <button className="btn-ghost !px-2 !py-1" title="Submit for review" onClick={() => go(() => update('documents', r.id, { status: 'pending_review' }), 'Sent for review', reload)}><ArchiveIcon size={14} /></button>}
        {(r.status === 'pending_review' || r.status === 'approved') && <button className="btn-ghost !px-2 !py-1" title={r.status === 'approved' ? 'Issue (locks snapshot)' : 'Approve'} onClick={async () => { if (r.status === 'approved') { if (!(await confirm('Issue this document? Its data snapshot will be locked and can no longer be edited.'))) return; } go(() => update('documents', r.id, { status: r.status === 'approved' ? 'issued' : 'approved' }), r.status === 'approved' ? 'Document issued' : 'Approved', reload); }}><FileCheck2 size={14} /></button>}
      </>)} />
    {pv && <Preview doc={pv} onClose={() => setPv(null)} />}
  </>);
}
