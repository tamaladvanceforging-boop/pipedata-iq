declare global { interface Window { PDQ: { root: string; nonce: string; adminUrl: string; version: string } } }
export type Row = Record<string, any>;
export interface Col { name: string; sql: string; required: boolean; fk: string | null; json: boolean }
export interface EntityMeta { label: string; group: string; list: string[]; columns: Col[]; readonly: boolean }
export interface Boot { entities: Record<string, EntityMeta>; nav: { id: string; label: string; icon: string }[]; doc_types: { quality: string[]; dispatch: string[] }; placeholders: string[]; categories: string[]; version: string }
export interface Page { items: Row[]; total: number; page: number; per_page: number; pages: number }

/** Same-origin REST URL (LocalWP often serves admin on a port the site URL omits, which would make fetches cross-origin). */
export function rest(path: string, query = ''): string {
  let root = window.PDQ.root;
  try { const u = new URL(root, location.href); root = location.origin + u.pathname + u.search; } catch { /* keep */ }
  const url = root + path.replace(/^\//, '');
  return query ? url + (url.includes('?') ? '&' : '?') + query.replace(/^\?/, '') : url;
}

export class ApiError extends Error { status: number; constructor(m: string, s: number) { super(m); this.status = s; } }

function qs(o: Record<string, any>) {
  const p = new URLSearchParams();
  const add = (k: string, v: any) => { if (v === undefined || v === null || v === '') return; if (typeof v === 'object') Object.entries(v).forEach(([a, b]) => add(`${k}[${a}]`, b)); else p.append(k, String(v)); };
  Object.entries(o).forEach(([k, v]) => add(k, v));
  const s = p.toString(); return s ? '?' + s : '';
}

export async function api<T = any>(path: string, opts: { method?: string; body?: any; form?: FormData; query?: Record<string, any> } = {}): Promise<T> {
  const headers: Record<string, string> = { 'X-WP-Nonce': window.PDQ.nonce };
  let body: BodyInit | undefined;
  if (opts.form) body = opts.form; else if (opts.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opts.body); }
  const url = rest(path, qs(opts.query || {}));
  const res = await fetch(url, { method: opts.method || 'GET', headers, body, credentials: 'same-origin' });
  let j: any = null; try { j = await res.json(); } catch { /* non-JSON */ }
  if (!res.ok) throw new ApiError((j && j.message) || `Request failed (${res.status})`, res.status);
  return j as T;
}

export const list = (e: string, q: Record<string, any>) => api<Page>(`e/${e}`, { query: q });
export const create = (e: string, b: Row) => api<Row>(`e/${e}`, { method: 'POST', body: b });
export const update = (e: string, id: number, b: Row) => api<Row>(`e/${e}/${id}`, { method: 'PUT', body: b });
export const archive = (e: string, id: number) => api(`e/${e}/${id}`, { method: 'DELETE' });
export const restore = (e: string, id: number) => api(`e/${e}/${id}/restore`, { method: 'POST' });
export const purge = (e: string, id: number) => api(`e/${e}/${id}/purge`, { method: 'POST' });
export const duplicate = (e: string, id: number, o: Row = {}) => api<Row>(`e/${e}/${id}/duplicate`, { method: 'POST', body: o });

export function download(filename: string, content: string, mime: string) {
  const b = new Blob([content], { type: mime + ';charset=utf-8' }); const a = document.createElement('a');
  a.href = URL.createObjectURL(b); a.download = filename; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}
export const label = (s: string) => s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()).replace(/\bId\b/, 'ID').replace(/\bPo\b/, 'PO').replace(/\bNps\b/, 'NPS').replace(/\bNb\b/, 'NB').replace(/\bDn\b/, 'DN').replace(/\bOd\b/, 'OD').replace(/\bLbs\b/, 'LBS');
