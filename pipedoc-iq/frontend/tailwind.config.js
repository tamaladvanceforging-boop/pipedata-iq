export default {
  darkMode: 'class',
  content: ['./src/**/*.{ts,tsx}'],
  theme: { extend: {
    colors: { graphite: '#18181B', brand: { DEFAULT: '#EA580C', 2: '#FB923C' }, surface: '#F4F4F5',
      ok: '#16A34A', warn: '#D97706', err: '#DC2626', draft: '#64748B', info: '#2563EB', sync: '#7C3AED' },
    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'], head: ['Manrope', 'Inter', 'sans-serif'], mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'] },
  } },
};
