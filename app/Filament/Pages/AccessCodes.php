<?php

namespace App\Filament\Pages;

use App\Access\Area;
use App\Filament\Concerns\BoxedPage;
use App\Models\AccessCode;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Codes Tageszugang wie /codes der PHP-Version: aktuell gültiger Code, Suche
 * nach Datum und die nächsten 30 Tage. Die Codes kommen per Import.
 */
class AccessCodes extends Page
{
    use BoxedPage;

    protected static ?string $slug = 'codes';

    protected static ?string $title = 'Codes Tageszugang';

    protected static ?string $navigationLabel = 'Codes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.access-codes';

    /** Wie ?datum= in der PHP-Version */
    #[Url(as: 'datum')]
    public ?string $datum = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->access()->can(Area::Codes);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $date = is_string($this->datum) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->datum) === 1 ? $this->datum : null;

        return [
            'current' => AccessCode::current(),
            'upcoming' => AccessCode::upcoming(30),
            'total' => AccessCode::query()->count(),
            'first' => AccessCode::query()->min('valid_on'),
            'last' => AccessCode::query()->max('valid_on'),
            'lookupDate' => $date,
            'lookup' => $date !== null ? AccessCode::forDate($date) : null,
        ];
    }
}
