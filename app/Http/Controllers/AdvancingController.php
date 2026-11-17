<?php

namespace App\Http\Controllers;

use App\Access\Module;
use App\Models\EventAdvancing;
use App\Models\Setting;
use App\Support\Advancings;
use App\Support\Audit;
use App\Support\EventDisplay;
use App\Support\VenueLogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Fragenkatalog für den Veranstalter (Modul Advancing): ohne Konto über den Link
 * aus der Mail, bis zum Ende des Tages nach der Veranstaltung oder bis der Planer
 * sperrt. Klassisches Formular ohne Livewire – die Seite enthält nur, was der
 * Katalog zeigt: Titel und Datum des Events, Fragen, Vorbelegung, Antworten.
 */
class AdvancingController extends Controller
{
    /** Der Link enthält den Schlüssel: nicht indexieren, nicht weitergeben, nicht zwischenspeichern. */
    private const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
    ];

    public function show(string $token): Response
    {
        $advancing = self::find($token);
        if (!$advancing->isLinkValid()) {
            return self::unavailable($advancing);
        }
        // Wer in VenueControl angemeldet ist (der Planer über „Als Veranstalter ansehen“), ist nicht der Veranstalter
        $preview = Auth::check();
        if (!$preview && $advancing->first_opened_at === null) {
            Audit::as(Advancings::ACTOR, fn (): bool => $advancing->update(['first_opened_at' => now()]));
        }
        $event = $advancing->event;

        return response()
            ->view('advancing', [
                'token' => $token,
                'sections' => self::sections($advancing),
                'templateName' => (string) $advancing->template_name,
                'intro' => (string) $advancing->intro,
                'eventTitle' => (string) $event->title,
                'eventDate' => $event->starts_at ? EventDisplay::weekday($event->starts_at) . ', ' . $event->starts_at->format('d.m.Y') : '',
                'validUntil' => $advancing->expires_at,
                'closed' => $advancing->isClosed(),
                'preview' => $preview,
                'submittedAt' => $advancing->submitted_at,
                'documentTitle' => ($event->starts_at ?? now())->format('Ymd') . ' ' . trim((string) $event->title) . ' – Advancing',
                'venue' => self::venue(),
                'logo' => VenueLogo::fitted(200, 64),
            ])
            ->withHeaders(self::HEADERS);
    }

    public function update(Request $request, string $token): RedirectResponse|Response
    {
        $advancing = self::find($token);
        if (!$advancing->isLinkValid() || $advancing->isClosed()) {
            return self::unavailable($advancing);
        }
        $submit = $request->input('aktion') === 'absenden';

        $errors = Advancings::save($advancing, (array) $request->input('a', []), (array) $request->input('o', []));
        if ($errors === [] && $submit) {
            $errors = Advancings::submit($advancing->refresh());
        }
        $back = redirect()->route('advancing.show', $token);
        if ($errors !== []) {
            return $back->withErrors($errors)->withInput();
        }

        return $back->with('status', $submit
            ? 'Abgeschickt – danke! Ihr könnt bis ' . $advancing->expires_at->format('d.m.Y, H:i') . ' Uhr weiter ändern und erneut absenden.'
            : 'Gespeichert.');
    }

    private static function find(string $token): EventAdvancing
    {
        abort_unless(Module::Advancing->isEnabled(), 404);
        $advancing = EventAdvancing::findByToken($token);
        abort_if($advancing === null || !$advancing->isSent(), 404);

        return $advancing;
    }

    private static function unavailable(EventAdvancing $advancing): Response
    {
        $reason = match (true) {
            $advancing->isRevoked() => 'revoked',
            $advancing->isExpired() => 'expired',
            default => 'closed',
        };

        return response()
            ->view('advancing-closed', ['reason' => $reason, 'venue' => self::venue()], 410)
            ->withHeaders(self::HEADERS);
    }

    /**
     * Abschnitte für die Seite: je Eintrag nur, was dort gebraucht wird.
     *
     * @return list<array{title: string, text: string, items: list<array<string, mixed>>}>
     */
    private static function sections(EventAdvancing $advancing): array
    {
        $readOnly = $advancing->isClosed();
        $sections = [];
        foreach ((array) $advancing->structure as $section) {
            $items = [];
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'info') {
                    $items[] = ['type' => 'info', 'label' => (string) ($item['label'] ?? ''), 'text' => (string) ($item['text'] ?? '')];

                    continue;
                }
                $key = (string) ($item['key'] ?? '');
                $value = (string) Advancings::value($advancing, $key);
                $items[] = [
                    'type' => (string) $item['type'],
                    'key' => $key,
                    'label' => (string) ($item['label'] ?? ''),
                    'help' => (string) ($item['help'] ?? ''),
                    'required' => (bool) ($item['required'] ?? false) && Advancings::editable($item),
                    'editable' => !$readOnly && Advancings::editable($item),
                    'options' => array_values(array_filter((array) ($item['options'] ?? []), 'is_string')),
                    'value' => $value,
                    'display' => Advancings::display($item, $value),
                ];
            }
            $sections[] = ['title' => (string) ($section['title'] ?? ''), 'text' => (string) ($section['text'] ?? ''), 'items' => $items];
        }

        return $sections;
    }

    private static function venue(): string
    {
        return Setting::lookup(Setting::VENUE_NAME) ?: (string) config('app.name');
    }
}
