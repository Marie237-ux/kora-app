<?php

namespace App\Domain\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a frozen document snapshot to PDF in French or English. Only the frozen data is used, so the same
 * document always reads the same; the language changes the labels, never the amounts or the number.
 */
final class DocumentRenderer
{
    public function pdf(array $snapshot, string $locale, bool $draft = false): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $html = view('documents.customer-document', [
                'doc' => $snapshot,
                'locale' => $locale,
                'draft' => $draft,
                'logo' => app(DocumentAssets::class)->dataUri($snapshot['logo_sha256'] ?? null),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }
        // No remote fetches and no embedded PHP: the document is built only from the snapshot.
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
