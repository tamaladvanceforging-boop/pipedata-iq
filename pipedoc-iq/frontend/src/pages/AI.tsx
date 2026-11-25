import { useEffect, useState } from 'react';
import { Eye, EyeOff, KeyRound, Trash2, Plug, Sparkles, ShieldAlert } from 'lucide-react';
import { api, Row } from '../api';
import { useApp } from '../ctx';
import { Badge, PageHead, Spinner } from '../ui';

export function AISettings() {
  const { toast, confirm } = useApp(); const [s, setS] = useState<Row | null>(null); const [key, setKey] = useState(''); const [show, setShow] = useState(false);
  const [models, setModels] = useState<Row[]>([]); const [modelErr, setModelErr] = useState(''); const [loadingModels, setLoadingModels] = useState(false); const [busy, setBusy] = useState(''); const [errs, setErrs] = useState<Row[]>([]);
  const fetchModels = async () => { setLoadingModels(true); try { const r = await api('ai/models'); setModels(r); setModelErr(''); } catch (e: any) { setModels([]); setModelErr(e.message); } finally { setLoadingModels(false); } };
  const load = () => { api('ai/settings').then((r) => { setS(r); if (r.has_key) fetchModels(); }).catch((e) => toast(e.message, 'err')); api('ai/errors').then((r) => setErrs(r.items)).catch(() => {}); };
  useEffect(load, []); // eslint-disable-line
  const run = async (name: string, fn: () => Promise<any>, ok?: string) => { setBusy(name); try { const r = await fn(); if (ok) toast(ok); return r; } catch (e: any) { toast(e.message, 'err'); load(); } finally { setBusy(''); } };
  const save = async () => { const r = await run('save', () => api('ai/key', { method: 'POST', body: { api_key: key } }), 'API key saved (encrypted on the server)'); if (r) { setS(r); setKey(''); fetchModels(); } };
  const test = async () => { const r = await run('test', () => api('ai/test', { method: 'POST' }), 'Connection OK'); if (r) { setModels(r.models); setModelErr(''); } };
  const patch = async (b: Row) => { const r = await run('patch', () => api('ai/settings', { method: 'POST', body: b })); if (r) setS(r); };
  if (!s) return <div className="p-10 text-center"><Spinner /></div>;
  return (
    <div className="max-w-3xl">
      <PageHead title="Gemini AI Settings" sub="Bring your own Google Gemini API key. AI is optional — all core features work without it." />
      <div className="mb-4 flex gap-2 rounded-lg border border-info/40 bg-info/10 p-3 text-xs"><ShieldAlert size={16} className="shrink-0 text-info" /><span>Your key is encrypted on the server and is never sent to the browser. A Gemini <i>consumer subscription</i> does not necessarily include API access — create an API key in Google AI Studio.</span></div>
      <div className="card space-y-4 p-4">
        <div><label className="lbl">API key {s.has_key && <span className="text-ok">· saved ({s.key_hint})</span>}</label>
          <div className="flex gap-2"><div className="relative flex-1"><input className="input font-mono pr-9" type={show ? 'text' : 'password'} autoComplete="off" placeholder={s.has_key ? '•••••••• (enter a new key to replace)' : 'Paste Gemini API key'} value={key} onChange={(e) => setKey(e.target.value)} /><button className="absolute right-2 top-2.5 opacity-60" onClick={() => setShow(!show)} aria-label="Show or hide key">{show ? <EyeOff size={16} /> : <Eye size={16} />}</button></div>
            <button className="btn-primary" disabled={!key || busy === 'save'} onClick={save}>{busy === 'save' ? <Spinner /> : <KeyRound size={14} />} Save</button></div></div>
        <div className="flex flex-wrap gap-2"><button className="btn-ghost" disabled={!s.has_key || !!busy} onClick={test}>{busy === 'test' ? <Spinner /> : <Plug size={14} />} Test connection & load models</button>
          <button className="btn-ghost text-err" disabled={!s.has_key} onClick={async () => { if (await confirm('Remove the stored API key? AI features will stop working until a new key is saved.')) { const r = await run('rm', () => api('ai/key', { method: 'DELETE' }), 'API key removed'); if (r) { setS(r); setModels([]); } } }}><Trash2 size={14} /> Remove key</button></div>
        <div><label className="lbl">Model {loadingModels && <span className="opacity-60">· loading available models…</span>}</label>
          <select className="input" value={s.model || ''} disabled={!s.has_key} onChange={(e) => patch({ model: e.target.value })}>{!models.find((m) => m.id === s.model) && s.model && <option value={s.model}>{s.model}</option>}<option value="">— select a model —</option>{models.map((m) => <option key={m.id} value={m.id}>{m.display} ({m.id})</option>)}</select>
          {modelErr && <p className="mt-1 rounded-lg border border-err/40 bg-err/10 p-2 text-xs text-err">{modelErr}</p>}{!s.has_key && <p className="mt-1 text-xs opacity-60">Save an API key first — models are loaded from your Google account.</p>}</div>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={s.consent_given} onChange={(e) => patch({ consent_given: e.target.checked })} /><span><b>I consent</b> to sending the specific text/labels I submit (instructions, template field labels, extracted text) to Google Gemini for processing. Confidential documents are never sent automatically.</span></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.enabled} disabled={!s.has_key || !s.model} onChange={(e) => patch({ enabled: e.target.checked })} /> Enable AI features</label>
      </div>
      <div className="card mt-4 grid grid-cols-3 gap-3 p-4 text-center"><div><div className="font-head text-2xl font-extrabold">{s.usage_requests}</div><div className="text-xs opacity-60">Requests</div></div><div><div className="font-head text-2xl font-extrabold">{s.usage_errors}</div><div className="text-xs opacity-60">Errors</div></div><div><Badge v={s.enabled ? 'active' : 'inactive'} /><div className="mt-1 text-xs opacity-60">Status</div></div></div>
      <div className="card mt-4 p-4"><h3 className="mb-2 font-head font-bold">Error log</h3>{errs.length === 0 ? <p className="text-sm opacity-60">No errors.</p> : errs.map((e) => <div key={e.id} className="border-b py-1 text-xs last:border-0" style={{ borderColor: 'var(--line)' }}><span className="opacity-50">{e.created_at}</span> — {e.summary}</div>)}</div>
    </div>
  );
}

export function AIAssistant() {
  const { toast, go } = useApp(); const [text, setText] = useState('Generate a draft Test Certificate and Packing List for 100 NB Class 150 WNRF Flange, quantity 50 pieces, using the selected company profile and verified heat number records.');
  const [r, setR] = useState<Row | null>(null); const [busy, setBusy] = useState(false);
  const run = async () => { setBusy(true); setR(null); try { setR(await api('ai/instruction', { method: 'POST', body: { text } })); } catch (e: any) { toast(e.message, 'err'); } finally { setBusy(false); } };
  return (
    <div className="max-w-4xl"><PageHead title="AI Assistant" sub="Natural-language instruction → structured parameters → your validated database. The AI never supplies technical data." />
      <div className="card p-4"><textarea className="input" rows={4} value={text} onChange={(e) => setText(e.target.value)} /><div className="mt-2 flex gap-2"><button className="btn-primary" onClick={run} disabled={busy || !text.trim()}>{busy ? <Spinner /> : <Sparkles size={14} />} Interpret</button><button className="btn-ghost" onClick={() => go('ai_settings')}>AI settings</button></div></div>
      {r && (<div className="card mt-4 p-4 text-sm"><h3 className="mb-2 font-head font-bold">Interpreted request</h3>
        <pre className="overflow-auto rounded bg-black/5 p-3 font-mono text-xs">{JSON.stringify(r.structured, null, 2)}</pre>
        <h3 className="mb-2 mt-4 font-head font-bold">Matching engineering records ({r.matches.length})</h3>
        {r.matches.length === 0 ? <p className="text-xs opacity-70">No match in the engineering database. Nothing was invented — import or add the record first.</p> : r.matches.map((m: Row) => <div key={m.id} className="flex justify-between border-b py-1 text-xs" style={{ borderColor: 'var(--line)' }}><span className="font-mono">{m.item_code}</span><span>{m.item_type || m.category} · NPS {m.nps} · {m.pressure_class || m.schedule}</span><Badge v={m.verification_status} /></div>)}
        <p className="mt-3 text-xs opacity-70">{r.note}</p><button className="btn-primary mt-2" onClick={() => go('generator')}>Continue in Document Generator</button></div>)}
    </div>
  );
}
