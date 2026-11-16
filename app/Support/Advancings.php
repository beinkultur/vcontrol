<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Filament\Resources\Events\EventResource;
use App\Mail\AdvancingSubmittedMail;
use App\Mail\DaysheetMail;
use App\Models\AdvancingTemplate;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventAdvancing;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Advancing (Modul Advancing): Zu einem Event bekommt der Veranstalter einen
 * Fragenkatalog per Link ohne Konto. Der Planer startet ihn aus einem Katalog
 * (Kopie), belegt vor – zum Teil automatisch aus dem Event – und verschickt den
 * Link wie ein Daysheet: gültig bis zum Ende des Tages nach der Veranstaltung.
 *
 * prefill: was der Planer vorbelegt; answers: was der Veranstalter davon
 * abweichend eingetragen hat. Angezeigt wird answers[key] ?? prefill[key].
 */
final class Advancings
{
    public const DEFAULT_SUBJECT = 'Advancing {event} – {datum}';

    public const DEFAULT_TEXT = "Hallo,\n\nzur Vorbereitung von {event} am {datum} in der {venue} bitten wir euch, unseren Fragenkatalog auszufüllen. Was wir schon wissen, ist eingetragen – bitte prüfen und ergänzen:\n{link}\n\nDer Link ist bis {gueltig_bis} gültig; ihr könnt zwischendurch speichern und später weitermachen.\n\nViele Grüße\n{venue}";

    public const PLACEHOLDER_HELP = '{event}, {datum} und {venue} werden ersetzt, beim Versand auch {link} und {gueltig_bis}. Fehlt {link}, steht der Link am Ende.';

    public const BUTTON_LABEL = 'Fragenkatalog öffnen';

    /** So steht der Veranstalter im Änderungsprotokoll (Audit::as) */
    public const ACTOR = 'Veranstalter (Advancing-Link)';

    /** Höchstlängen je Typ; Zahlen 0 bis MAX_NUMBER */
    private const MAX_LENGTH = ['text' => 500, 'textarea' => 5000];

    private const MAX_NUMBER = 9999;

    /** Advancing aus einem Katalog starten: Kopie der Fragen, Vorbelegung aus dem Event. */
    public static function start(Event $event, AdvancingTemplate $template): EventAdvancing
    {
        if ($event->advancing()->exists()) {
            throw new InvalidArgumentException('Für dieses Event gibt es schon ein Advancing.');
        }
        $structure = (array) $template->structure;

        return $event->advancing()->create([
            'advancing_template_id' => $template->id,
            'template_name' => $template->name,
            'intro' => $template->intro,
            'structure' => $structure,
            'prefill' => self::prefillFromEvent($event, $structure),
        ]);
    }

    /**
     * Werte der gebundenen Fragen (AdvancingCatalog::BINDINGS) aus dem Event,
     * ohne leere.
     *
     * @param  array<mixed>  $structure
     * @return array<string, string>
     */
    public static function prefillFromEvent(Event $event, array $structure): array
    {
        $event->loadMissing('schedule');
        $values = [];
        foreach (AdvancingCatalog::questions($structure) as $key => $question) {
            $binding = $question['binding'] ?? null;
            $value = is_string($binding) ? self::bindingValue($event, $binding) : null;
            if ($value !== null && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    private static function bindingValue(Event $event, string $binding): ?string
    {
        [$source, $field] = explode('.', $binding, 2) + [1 => ''];

        return match ($source) {
            'event' => match ($field) {
                'title' => (string) $event->title,
                'date' => $event->starts_at?->format('Y-m-d'),
                default => null,
            },
            'schedule' => self::time($event->schedule?->getAttribute($field)),
            'onsite' => match ($field) {
                'name' => trim($event->onsite_first_name . ' ' . $event->onsite_last_name),
                'email' => $event->onsite_email,
                'phone' => $event->onsite_phone,
                default => null,
            },
            default => null,
        };
    }

    /** „11:30:00“ → „11:30 Uhr“ */
    private static function time(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('H:i') . ' Uhr';
        }
        $value = trim((string) $value);

        return preg_match('/^(\d{1,2}):(\d{2})/', $value, $match) === 1 ? sprintf('%02d:%s Uhr', $match[1], $match[2]) : null;
    }

    /**
     * Zur Wahl bei Personenfragen: „tba.“, aktive Personen-Konten (Mitarbeiter der
     * Venue, Freelancer), nicht archivierte Kontakte.
     *
     * @return array<string, string>
     */
    public static function personOptions(): array
    {
        $options = ['tba' => 'tba.'];
        $users = User::query()
            ->where('is_active', true)
            ->whereIn('account_type', array_map(fn (AccountType $type): string => $type->value, array_filter(AccountType::cases(), fn (AccountType $type): bool => $type->hasPositions())))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
        foreach ($users as $user) {
            $options["user:{$user->id}"] = self::label($user->getFilamentName(), $user->email, $user->phone);
        }
        foreach (Contact::query()->where('is_archived', false)->orderBy('name')->get() as $contact) {
            $options["contact:{$contact->id}"] = self::label((string) $contact->name, $contact->email, $contact->phone);
        }

        return $options;
    }

    /** „Name  |  E-Mail  |  Telefon“ zum Verweis (user:ID, contact:ID), immer der aktuelle Stand; sonst „tba.“ */
    public static function personLabel(?string $ref): string
    {
        [$type, $id] = explode(':', (string) $ref, 2) + [1 => ''];
        $id = ctype_digit($id) ? (int) $id : 0;
        $person = match ($type) {
            'user' => $id > 0 ? User::query()->find($id) : null,
            'contact' => $id > 0 ? Contact::query()->find($id) : null,
            default => null,
        };

        return match (true) {
            $person instanceof User => self::label($person->getFilamentName(), $person->email, $person->phone),
            $person instanceof Contact => self::label((string) $person->name, $person->email, $person->phone),
            default => 'tba.',
        };
    }

    private static function label(string $name, ?string $email, ?string $phone): string
    {
        return implode('  |  ', array_values(array_filter(array_map(fn (?string $part): string => trim((string) $part), [$name, $email, $phone]), fn (string $part): bool => $part !== '')));
    }

    /** Was gilt: Antwort des Veranstalters, sonst Vorbelegung. */
    public static function value(EventAdvancing $advancing, string $key): ?string
    {
        $value = ((array) $advancing->answers)[$key] ?? ((array) $advancing->prefill)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Fragen, bei denen der Veranstalter etwas anderes eingetragen hat als die
     * aktuelle Vorbelegung.
     *
     * @return list<string>
     */
    public static function changedKeys(EventAdvancing $advancing): array
    {
        $prefill = (array) $advancing->prefill;
        $changed = [];
        foreach ((array) $advancing->answers as $key => $answer) {
            if (self::normalize($answer) !== self::normalize($prefill[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    public static function normalize(mixed $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    /** Wert zum Lesen: Datum deutsch, Personen mit Kontaktdaten, leer „–“. */
    public static function display(array $question, ?string $value): string
    {
        $value = self::normalize($value);
        if ($value === '') {
            return ($question['type'] ?? null) === 'person' ? 'tba.' : '–';
        }

        return match ($question['type'] ?? null) {
            'date' => self::date($value),
            'person' => self::personLabel($value),
            default => $value,
        };
    }

    private static function date(string $value): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->format('d.m.Y');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Eingaben des Veranstalters speichern. Übernommen wird nur, was er gegenüber
     * dem beim Laden gezeigten Wert ($shown) geändert hat – so überschreibt eine
     * zweite, ältere Seite nicht, was jemand anders inzwischen gespeichert hat.
     * Gesperrte Fragen, Personen, Infotexte und Unbekanntes bleiben außen vor.
     * Gleich der Vorbelegung heißt: keine eigene Antwort. Bei Fehlern wird nichts
     * gespeichert.
     *
     * @param  array<mixed>  $posted  key => Wert aus a[…]
     * @param  array<mixed>  $shown  key => Wert beim Laden aus o[…]
     * @return array<string, string> Fehler je Feld (a.key)
     */
    public static function save(EventAdvancing $advancing, array $posted, array $shown): array
    {
        $questions = $advancing->questions();
        $errors = [];
        $updates = [];
        foreach ($posted as $key => $value) {
            $question = is_string($key) ? ($questions[$key] ?? null) : null;
            if ($question === null || !self::editable($question)) {
                continue;
            }
            $value = self::normalize(is_scalar($value) ? $value : '');
            $before = self::normalize(is_scalar($shown[$key] ?? null) ? $shown[$key] : '');
            if ($value === $before) {
                continue;
            }
            $error = self::invalid($question, $value);
            if ($error !== null) {
                $errors["a.{$key}"] = $error;

                continue;
            }
            $updates[$key] = $value;
        }
        if ($errors !== []) {
            return $errors;
        }

        $answers = (array) $advancing->answers;
        $prefill = (array) $advancing->prefill;
        foreach ($updates as $key => $value) {
            if ($value === self::normalize($prefill[$key] ?? null)) {
                unset($answers[$key]);
            } else {
                $answers[$key] = $value;
            }
        }
        Audit::as(self::ACTOR, fn (): bool => $advancing->update(['answers' => $answers === [] ? null : $answers, 'last_saved_at' => now()]));

        return [];
    }

    /** Darf der Veranstalter die Frage ändern? Personen wählt immer der Planer. */
    public static function editable(array $question): bool
    {
        return !($question['locked'] ?? false) && !in_array($question['type'] ?? null, ['info', 'person'], true);
    }

    private static function invalid(array $question, string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $type = (string) ($question['type'] ?? 'text');

        return match ($type) {
            'number' => ctype_digit($value) && (int) $value <= self::MAX_NUMBER ? null : 'Bitte eine ganze Zahl von 0 bis ' . self::MAX_NUMBER . ' eintragen.',
            'date' => self::isDate($value) ? null : 'Bitte ein Datum wählen.',
            'yes_no' => in_array($value, ['ja', 'nein'], true) ? null : 'Bitte ja oder nein wählen.',
            'choice' => in_array($value, (array) ($question['options'] ?? []), true) ? null : 'Bitte einen Wert aus der Liste wählen.',
            default => mb_strlen($value) <= (self::MAX_LENGTH[$type] ?? self::MAX_LENGTH['text'])
                ? null
                : 'Höchstens ' . (self::MAX_LENGTH[$type] ?? self::MAX_LENGTH['text']) . ' Zeichen.',
        };
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Absenden: Pflichtfragen, die der Veranstalter ausfüllt, brauchen einen Wert.
     * Danach Mail an den Planer, der den Link verschickt hat, und an die
     * Antwortadresse der Venue; scheitert sie, bleibt die Abgabe trotzdem.
     *
     * @return array<string, string> Fehler je Feld (a.key)
     */
    public static function submit(EventAdvancing $advancing): array
    {
        $missing = [];
        foreach ($advancing->questions() as $key => $question) {
            if (($question['required'] ?? false) && self::editable($question) && self::normalize(self::value($advancing, $key)) === '') {
                $missing["a.{$key}"] = 'Bitte ausfüllen.';
            }
        }
        if ($missing !== []) {
            return $missing;
        }

        Audit::as(self::ACTOR, fn (): bool => $advancing->update(['submitted_at' => now(), 'submit_count' => $advancing->submit_count + 1]));
        self::notifySubmitted($advancing);

        return [];
    }

    private static function notifySubmitted(EventAdvancing $advancing): void
    {
        $sender = $advancing->sender;
        $recipients = Daysheets::emails([
            $sender instanceof User && $sender->is_active ? $sender->email : null,
            MailIdentity::replyTo()?->address,
        ]);
        if ($recipients === []) {
            return;
        }
        $event = $advancing->event;
        $link = EventResource::getUrl('edit', ['record' => $event]) . '?phase=planung&bereich=advancing';

        try {
            Mail::to($recipients)->send(new AdvancingSubmittedMail(
                eventTitle: (string) $event->title,
                eventDate: Daysheets::fill('{datum}', $event),
                templateName: (string) $advancing->template_name,
                submitCount: (int) $advancing->submit_count,
                changedCount: count(self::changedKeys($advancing)),
                link: $link,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Vorbelegung des Versand-Dialogs: An der Ansprechpartner vor Ort.
     *
     * @return array{to: list<string>, bcc: list<string>, subject: string, body: string}
     */
    public static function defaults(EventAdvancing $advancing): array
    {
        $event = $advancing->event;

        return [
            'to' => Daysheets::emails([$event->onsite_email]),
            'bcc' => [],
            'subject' => Daysheets::fill(self::DEFAULT_SUBJECT, $event),
            'body' => Daysheets::fill(self::DEFAULT_TEXT, $event),
        ];
    }

    /**
     * Link per Mail verschicken. Erneutes Senden schickt denselben Link – außer
     * er war gesperrt: dann gibt es einen neuen, der alte bleibt ungültig. Gültig
     * bis zum Ende des Tages nach der Veranstaltung (neu berechnet bei jedem
     * Senden). Scheitert der Versand, bleibt nichts gespeichert.
     *
     * @param  list<string>  $to
     * @param  list<string>  $bcc
     */
    public static function send(EventAdvancing $advancing, array $to, array $bcc, string $subject, string $body, ?User $sender): EventAdvancing
    {
        $to = Daysheets::emails($to);
        $bcc = array_values(array_diff(Daysheets::emails($bcc), $to));
        if ($to === []) {
            throw new InvalidArgumentException('Der Fragenkatalog braucht mindestens einen Empfänger unter „An“.');
        }
        if ($advancing->isClosed()) {
            throw new InvalidArgumentException('Das Advancing ist abgeschlossen – der Link lässt sich nicht mehr verschicken.');
        }
        $event = $advancing->event;
        $validUntil = Daysheets::validUntil($event);
        if ($validUntil === null) {
            throw new InvalidArgumentException('Das Event hat noch kein Datum – ohne Datum gibt es keinen Link.');
        }
        if ($validUntil->isPast()) {
            throw new InvalidArgumentException('Die Veranstaltung ist vorbei – ein neuer Link wäre schon abgelaufen.');
        }
        if (!str_contains($body, '{link}')) {
            $body = rtrim($body) . "\n\n{link}";
        }
        $token = filled($advancing->token) && !$advancing->isRevoked() ? (string) $advancing->token : EventAdvancing::newToken();
        $link = route('advancing.show', $token);

        try {
            DB::transaction(function () use ($advancing, $to, $bcc, $subject, $body, $sender, $token, $link, $validUntil, $event): void {
                $advancing->update([
                    'token' => $token,
                    'token_hash' => EventAdvancing::hashToken($token),
                    'revoked_at' => null,
                    'expires_at' => $validUntil,
                    'sent_at' => now(),
                    'sent_by' => $sender?->id,
                    'recipients_to' => $to,
                    'recipients_bcc' => $bcc,
                ]);

                Mail::to($to)->bcc($bcc)->send(new DaysheetMail(
                    subjectLine: Daysheets::fill(trim($subject), $event, $link, $validUntil),
                    text: Daysheets::fill(str_replace(["\r\n", "\r"], "\n", $body), $event, $link, $validUntil),
                    replyToAddress: $sender?->email,
                    replyToName: $sender?->getFilamentName(),
                    link: $link,
                    buttonLabel: self::BUTTON_LABEL,
                ));
            });
        } catch (Throwable $exception) {
            $advancing->refresh();

            throw $exception;
        }

        return $advancing;
    }
}
