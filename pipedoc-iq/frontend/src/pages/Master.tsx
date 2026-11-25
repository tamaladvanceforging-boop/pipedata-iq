import { useState } from 'react';
import { EntityPage } from './EntityPage';

const TABS: { id: string; label: string; entity: string; defaults?: Record<string, any>; filters?: Record<string, any> }[] = [
  { id: 'cat', label: 'Product Categories', entity: 'product_categories' }, { id: 'types', label: 'Product Types', entity: 'product_types' },
  { id: 'grades', label: 'Material Grades', entity: 'material_grades' }, { id: 'std', label: 'Standards', entity: 'engineering_standards' }, { id: 'props', label: 'Material Properties', entity: 'material_properties' },
  { id: 'mfr', label: 'Manufacturers', entity: 'manufacturers' },
  ...[['consignee', 'Consignees'], ['inspection_agency', 'Inspection Agencies'], ['project', 'Projects'], ['material_code', 'Material Codes'], ['delivery_location', 'Delivery Locations']].map(([t, l]) => ({ id: t, label: l, entity: 'master_items', defaults: { list_type: t }, filters: { list_type: t } })),
  { id: 'wo', label: 'Work Orders', entity: 'purchase_orders', defaults: { po_type: 'work' }, filters: { po_type: 'work' } },
  { id: 'so', label: 'Sales Orders', entity: 'purchase_orders', defaults: { po_type: 'sales' }, filters: { po_type: 'sales' } },
];
export function Master() {
  const [t, setT] = useState(TABS[0]);
  return (
    <div>
      <div className="mb-4 flex flex-wrap gap-1">{TABS.map((x) => <button key={x.id} onClick={() => setT(x)} className={`rounded-full px-3 py-1 text-xs font-medium ${t.id === x.id ? 'bg-brand text-white' : 'btn-ghost !py-1'}`}>{x.label}</button>)}</div>
      <EntityPage key={t.id} entity={t.entity} title={t.label} defaults={t.defaults} filters={t.filters} hideCols={t.entity === 'master_items' ? [] : undefined} />
    </div>
  );
}
