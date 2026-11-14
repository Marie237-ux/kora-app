import { notFound } from 'next/navigation';
import { AuthPanel, Preferences } from '@kora/ui';
export default async function Page({
  params,
}: {
  params: Promise<{ locale: string; auth: string }>;
}) {
  const { locale, auth } = await params;
  if (locale !== 'fr' && locale !== 'en') notFound();
  const modes: Record<string, 'login' | 'register' | 'forgot' | 'reset'> = {
    login: 'login',
    register: 'register',
    'forgot-password': 'forgot',
    'reset-password': 'reset',
  };
  if (!modes[auth]) notFound();
  return (
    <main className="wrap">
      <Preferences locale={locale} />
      <AuthPanel locale={locale} mode={modes[auth]} returnTo={'/' + locale} />
    </main>
  );
}
