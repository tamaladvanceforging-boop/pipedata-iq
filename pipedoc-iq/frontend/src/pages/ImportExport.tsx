import { useState } from 'react';
import { Upload, FileDown, CheckCircle2 } from 'lucide-react';
import { api, download, label, Row } from '../api';
import { useApp } from '../ctx';
import { PageHead, Spinner } from '../ui';

export function ImportExport() {
  const { boot, toast } = useApp(); const ents = Object.entries(boot.entities).filter(([, m]) => !m.readonly);
  const [entity, setEntity] = useState('engineering_dimensions'); const [file, setFile] = useState<File | null>(null);
  const [prev, setPrev] = useState<Row | null>(null); const [map, setMap] = useState<Record<string, string | null>>({}); const [mode, setMode] = useState('skip_duplicates');
  const [busy, setBusy] = useState(false); const [res, setRes] = useState<Row | null>(null);
  const form = () => { const fd = new FormData(); fd.append('file', file as File); return fd; };
  const preview = async () => { if (!file) return; setBusy(true); setRes(null); try { const r = await api('import/' + entity + '/preview', { method: 'POST', form: form() }); setPrev(r); setMap(r.mapping); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } };
  const commit = async () => { if (!file) return; setBusy(true); try { const fd = form(); fd.append('mapping', JSON.stringify(map)); fd.append('mode', mode); setRes(await api('import/' + entity + '/commit', { method: 'POST', form: fd })); toast('Import finished'); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } };
  const cols = boot.entities[entity]?.columns.map((c) => c.name) || [];
  const template = () => download(`${entity}-template.csv`, cols.join(',') + '\n', 'text/csv');
  const exportIt = async (format: string) => { try { const r = await api('export/' + entity, { query: { format } }); download(r.filename, r.content, r.mime); toast(`Exported ${r.count} rows`); } catch (e: any) { toast(e.message, 'err'); } };
  return (
    <div>
      <PageHead title="Import / Export" sub="Bulk-load your own datasets (CSV, JSON, XLSX). Source, revision and verification columns are preserved." />
      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-3">
        <div><label className="lbl">Dataset</label><select className="input" value={entity} onChange={(e) => { setEntity(e.target.value); setPrev(null); setRes(null); }}>{ents.map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}</select></div>
        <div className="sm:col-span-2 flex items-end gap-2 flex-wrap"><button className="btn-ghost" onClick={template}><FileDown size={14} /> CSV header template</button><button className="btn-ghost" onClick={() => exportIt('csv')}><FileDown size={14} /> Export CSV</button><button className="btn-ghost" onClick={() => exportIt('json')}><FileDown size={14} /> Export JSON</button></div>
      </div>
      <div className="card p-4">
        <h3 className="mb-2 font-head font-bold">Import</h3>
        <label className="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed p-8 text-center hover:border-brand" style={{ borderColor: 'var(--line)' }} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); setFile(e.dataTransfer.files[0] || null); setPrev(null); }}>
          <Upload /><span>{file ? file.name : 'Drag & drop a CSV / JSON / XLSX file, or click to browse'}</span>
          <input type="file" className="hidden" accept=".csv,.json,.xlsx,.xls,.xlsm,.txt" onChange={(e) => { setFile(e.target.files?.[0] || null); setPrev(null); setRes(null); }} />
        </label>
        <div className="mt-3"><button className="btn-primary" disabled={!file || busy} onClick={preview}>{busy && <Spinner />} Preview & map columns</button></div>
        {prev && (<div className="mt-4">
          <p className="mb-2 text-sm"><b>{prev.total_rows.toLocaleString()}</b> rows found. Review the column mapping (unmapped columns are ignored):</p>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{prev.headers.map((h: string) => (<div key={h} className="flex items-center gap-2 text-xs"><span className="w-1/2 truncate font-mono" title={h}>{h}</span>→<select className="input !py-1" value={map[h] || ''} onChange={(e) => setMap({ ...map, [h]: e.target.value || null })}><option value="">(ignore)</option>{cols.map((c) => <option key={c} value={c}>{label(c)}</option>)}</select></div>))}</div>
          <div className="mt-3 overflow-x-auto rounded-lg border" style={{ borderColor: 'var(--line)' }}><table className="text-xs"><thead><tr>{prev.headers.map((h: string) => <th key={h} className="th">{h}</th>)}</tr></thead><tbody>{prev.sample.map((r: Row, i: number) => <tr key={i} className="border-t" style={{ borderColor: 'var(--line)' }}>{prev.headers.map((h: string) => <td key={h} className="td font-mono">{String(r[h] ?? '')}</td>)}</tr>)}</tbody></table></div>
          <div className="mt-3 flex flex-wrap items-center gap-3"><select className="input !w-auto" value={mode} onChange={(e) => setMode(e.target.value)}><option value="skip_duplicates">Skip duplicates</option><option value="update">Update duplicates</option><option value="insert_all">Insert everything</option></select><button className="btn-primary" disabled={busy} onClick={commit}>{busy && <Spinner />} Import {prev.total_rows.toLocaleString()} rows</button></div>
        </div>)}
        {res && (<div className="mt-4 rounded-lg border border-ok/40 bg-ok/10 p-3 text-sm"><div className="flex items-center gap-2 font-medium"><CheckCircle2 size={16} className="text-ok" />Inserted {res.inserted}, updated {res.updated}, skipped {res.skipped}, errors {res.errors.length}</div>{res.errors.slice(0, 20).map((e: Row, i: number) => <div key={i} className="text-xs text-err">Row {e.row}: {e.error}</div>)}</div>)}
      </div>
    </div>
  );
}
