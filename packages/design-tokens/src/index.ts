export const locales = ['fr', 'en'] as const;
export const themes = ['light', 'dark', 'system'] as const;
export const businessTimezone = 'Africa/Douala';
export const motion = {
  scene: 1000,
  stagger: 90,
  reading: 8000,
  feedback: 160,
  distance: 36,
  easing: 'cubic-bezier(.22,1,.36,1)',
} as const;
export function formatXaf(amount: number | bigint | string, locale: 'fr' | 'en') {
  return new Intl.NumberFormat(locale === 'fr' ? 'fr-CM' : 'en-CM', {
    style: 'currency',
    currency: 'XAF',
    maximumFractionDigits: 0,
  }).format(typeof amount === 'string' ? BigInt(amount) : amount);
}
export const preferenceScript = `(() => {try {const t=localStorage.getItem('kora.theme')||'system';document.documentElement.dataset.theme=t;document.documentElement.style.colorScheme=t==='system'?'light dark':t;}catch{document.documentElement.dataset.theme='system';}})();`;
