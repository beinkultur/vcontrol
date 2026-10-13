<?php

namespace App\Support;

use App\Models\Costing;
use App\Models\Setting;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Costing als PDF (A4 hoch) mit Dompdf, aus der Vorlage costing-pdf: Kopf mit
 * dem Logo der Halle (VenueLogo, sonst ihr Name), Event und Preisliste, die
 * Posten nach Abschnitten, Summen netto, Mehrwertsteuer und brutto, darunter
 * Anmerkung und Hinweise der Preisliste. Ohne Zugriff auf fremde Adressen:
 * Bilder kommen als data-URI, Skripte aus.
 */
final class CostingPdf
{
    public static function render(Costing $costing): string
    {
        $costing->loadMissing(['event.promoter', 'priceList', 'items']);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([base_path('vendor/dompdf/dompdf')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('costing-pdf', [
            'costing' => $costing,
            'event' => $costing->event,
            'venue' => Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name'),
            'venueLogo' => VenueLogo::forPdf(62, 20),
            'sections' => $costing->printedSections(),
            'logo' => 'data:image/png;base64,' . base64_encode((string) file_get_contents(public_path('images/logo-mail.png'))),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        // Seitenzahlen unten rechts, auf jeder Seite
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 34, 'Seite {PAGE_NUM} von {PAGE_COUNT}', $font, 7, [0.47, 0.47, 0.47]);

        return (string) $dompdf->output();
    }
}
