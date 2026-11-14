import { notFound } from 'next/navigation';
import { Operations } from '../../components/Operations';
export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (locale !== 'fr' && locale !== 'en') notFound();
  return <Operations locale={locale} />;
}
