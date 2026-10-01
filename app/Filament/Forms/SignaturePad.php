<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\Field;

/**
 * Unterschrift mit Finger, Stift oder Maus. Der Zustand ist wie in der
 * PHP-Version ein PNG als data:-URL (weißer Grund, damit sie auch im dunklen
 * Modus und im Druck lesbar bleibt). Ohne Build-Schritt: Alpine im View.
 */
class SignaturePad extends Field
{
    public const MAX_LENGTH = 1_500_000;

    protected string $view = 'filament.forms.signature-pad';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }
            if (!self::isValid($value)) {
                $fail('Die Unterschrift konnte nicht gelesen werden – bitte neu unterschreiben.');
            }
        });
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= self::MAX_LENGTH
            && preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+=*$#', $value) === 1;
    }
}
