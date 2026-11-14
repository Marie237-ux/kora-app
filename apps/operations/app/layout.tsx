import type { ReactNode } from 'react';
import { headers } from 'next/headers';
import '@kora/design-tokens/styles.css';
import { preferenceScript } from '@kora/design-tokens';
export const metadata = { title: 'KORA · Operations', robots: { index: false, follow: false } };
export default async function RootLayout({ children }: { children: ReactNode }) {
  const request = await headers();
  const locale = request.get('x-kora-locale') === 'en' ? 'en' : 'fr';
  return (
    <html lang={locale + '-CM'} data-theme="system" suppressHydrationWarning>
      <head>
        <script
          nonce={request.get('x-nonce') ?? undefined}
          dangerouslySetInnerHTML={{ __html: preferenceScript }}
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
