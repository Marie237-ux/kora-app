import { notFound } from 'next/navigation';
import { ScenePreview } from '../../../../components/ScenePreview';
export default async function Page({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;
  if (locale !== 'fr' && locale !== 'en') notFound();
  return <ScenePreview locale={locale} id={id} />;
}
