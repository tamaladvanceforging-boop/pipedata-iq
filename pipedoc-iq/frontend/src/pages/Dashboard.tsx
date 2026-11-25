import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AlertTriangle, Info, XCircle, Plus, FilePlus2, Upload, Flame } from 'lucide-react';
import { api, Row } from '../api';
import { useApp } from '../ctx';
import { ErrorBox, PageHead, Spinner, Empty } from '../ui';

const KPIS: [string, string, string][] = [
  ['engineering_records', 'Engineering Records', 'engineering_dimensions'], ['documents', 'Generated Documents', 'documents'], ['test_certificates', 'Test Certificates', 'tc'], ['dispatch_documents', 'Dispatch Documents', 'documents'],
  ['templates', 'Templates', 'templates'], ['active_companies', 'Active Companies', 'company_profiles'], ['pending_reviews', 'Pending Reviews', 'documents'], ['approved_documents', 'Approved Documents', 'documents'],
];
export function Dashboard() {
  const { go, toast } = useApp(); const [d, setD] = useState<Row | null>(null); const [err, setErr] = useState('');
  useEffect(() => { api('dashboard').then(setD).catch((e) => setErr(e.message)); }, []);
  if (err) return <ErrorBox msg={err} />; if (!d) return <div className="p-10 text-center"><Spinner /></div>;
  const lvl = { warning: <AlertTriangle size={15} className="text-warn" />, error: <XCircle size={15} className="text-err" />, info: <Info size={15} className="text-info" /> } as any;
  const max = Math.max(1, ...d.by_type.map((x: Row) => Number(x.value)));
  return (
    <div>
      <PageHead title="Dashboard" sub="Live figures from your PipeDoc IQ database">
        <button className="btn-primary" onClick={() => go('generator')}><FilePlus2 size={14} /> New document</button>
        <button className="btn-ghost" onClick={() => go('import_export')}><Upload size={14} /> Import data</button>
        <button className="btn-ghost" onClick={() => go('heat_numbers')}><Flame size={14} /> Add heat number</button>
        <button className="btn-ghost" onClick={() => go('templates')}><Plus size={14} /> Upload template</button>
      </PageHead>
      {d.kpi.engineering_records === 0 && d.kpi.documents === 0 && <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand/40 bg-orange-500/10 p-3 text-sm"><span>Your database is empty. Load the labelled DEMO set to try the whole workflow, or import your own data.</span><span className="flex gap-2"><button className="btn-primary" onClick={async () => { try { await api('demo/load', { method: 'POST' }); toast('Demo data loaded'); setD(await api('dashboard')); } catch (e: any) { toast(e.message, 'err'); } }}>Load demo data</button><button className="btn-ghost" onClick={() => go('import_export')}>Import my data</button></span></div>}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {KPIS.map(([k, l, to]) => (
          <button key={k} className="card p-4 text-left transition hover:border-brand" onClick={() => go(to)}>
            <div className="text-xs opacity-60">{l}</div><div className="mt-1 font-head text-3xl font-extrabold">{Number(d.kpi[k]).toLocaleString()}</div>
          </button>))}
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className="card p-4 lg:col-span-2"><h3 className="mb-2 font-head font-bold">Monthly document generation</h3>
          <div className="h-60"><ResponsiveContainer><BarChart data={d.monthly}><CartesianGrid strokeDasharray="3 3" stroke="var(--line)" /><XAxis dataKey="month" tick={{ fontSize: 11, fill: 'var(--muted)' }} /><YAxis allowDecimals={false} tick={{ fontSize: 11, fill: 'var(--muted)' }} /><Tooltip contentStyle={{ background: 'var(--card)', border: '1px solid var(--line)' }} /><Bar dataKey="count" name="Documents" fill="#EA580C" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div></div>
        <div className="card p-4"><h3 className="mb-2 font-head font-bold">Reports by category</h3>
          {d.by_type.length === 0 ? <Empty title="No documents yet" /> : <div className="space-y-2">{d.by_type.map((x: Row) => (<div key={x.name}><div className="flex justify-between text-xs"><span className="truncate pr-2">{x.name}</span><b className="font-mono">{x.value}</b></div><div className="h-2 rounded bg-black/10"><div className="h-2 rounded bg-brand" style={{ width: `${(Number(x.value) / max) * 100}%` }} /></div></div>))}</div>}</div>
        <div className="card p-4"><h3 className="mb-2 font-head font-bold">Data validation alerts</h3>
          {d.alerts.length === 0 ? <p className="text-sm text-ok">No alerts — data looks consistent.</p> : d.alerts.map((a: Row, i: number) => (<button key={i} onClick={() => go(a.link)} className="mb-2 flex w-full items-start gap-2 rounded-lg border p-2 text-left text-sm hover:border-brand" style={{ borderColor: 'var(--line)' }}>{lvl[a.level]}<span>{a.message}</span></button>))}</div>
        <div className="card p-4"><h3 className="mb-2 font-head font-bold">Recently used templates</h3>
          {d.recent_templates.length === 0 ? <Empty title="No template usage yet" /> : d.recent_templates.map((t: Row) => (<div key={t.id} className="flex justify-between border-b py-2 text-sm last:border-0" style={{ borderColor: 'var(--line)' }}><div><div className="font-medium">{t.name}</div><div className="text-xs opacity-60">{t.doc_type}</div></div><div className="text-right text-xs opacity-70">{t.uses} use(s)<br />{t.last_used}</div></div>))}</div>
        <div className="card p-4"><h3 className="mb-2 font-head font-bold">Recent activity</h3>
          {d.recent_activity.length === 0 ? <Empty title="No activity yet" /> : d.recent_activity.map((a: Row) => (<div key={a.id} className="border-b py-1.5 text-xs last:border-0" style={{ borderColor: 'var(--line)' }}><div>{a.summary || a.action}</div><div className="opacity-50">{a.created_at}</div></div>))}</div>
      </div>
    </div>
  );
}
