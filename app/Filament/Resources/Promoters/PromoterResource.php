<?php

namespace App\Filament\Resources\Promoters;

use App\Filament\Resources\Promoters\Pages\CreatePromoter;
use App\Filament\Resources\Promoters\Pages\EditPromoter;
use App\Filament\Resources\Promoters\Pages\ListPromoters;
use App\Filament\Resources\Promoters\Schemas\PromoterForm;
use App\Filament\Resources\Promoters\Tables\PromotersTable;
use App\Models\Promoter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PromoterResource extends Resource
{
    protected static ?string $model = Promoter::class;

    protected static ?string $slug = 'veranstalter';

    protected static ?string $modelLabel = 'Veranstalter';

    protected static ?string $pluralModelLabel = 'Veranstalter';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PromoterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromotersTable::configure($table);
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'short_name', 'customer_no', 'city'];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoters::route('/'),
            'create' => CreatePromoter::route('/create'),
            'edit' => EditPromoter::route('/{record}/edit'),
        ];
    }
}
