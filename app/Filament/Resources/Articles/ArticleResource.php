<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\ManageArticles;
use App\Models\Article;
use App\Models\ArticleCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Artikel für Bestellscheine mit Einheit und Preis. Die PHP-Version hat dafür
 * keine Seite (Daten kamen aus AppSheet); Preise ändern sich aber. Bestellte
 * Artikel werden deaktiviert statt gelöscht, alte Scheine behalten ihren Preis.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'artikel';

    protected static ?string $modelLabel = 'Artikel';

    protected static ?string $pluralModelLabel = 'Artikel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 43;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('category_id')
                    ->label('Kategorie')
                    ->relationship('category', 'name', fn (Builder $query): Builder => $query->orderBy('sort_order'))
                    ->createOptionForm([TextInput::make('name')->label('Name')->required()->maxLength(120)])
                    ->createOptionUsing(fn (array $data): int => ArticleCategory::create([
                        'name' => $data['name'],
                        'sort_order' => (int) ArticleCategory::max('sort_order') + 10,
                    ])->getKey())
                    ->required(),
                TextInput::make('name')->label('Name')->required()->maxLength(200),
                TextInput::make('unit')->label('Einheit')->placeholder('z. B. Kiste (20 Flaschen)')->maxLength(80),
                TextInput::make('price')->label('Preis')->numeric()->minValue(0)->suffix('€'),
                TextInput::make('short_name')->label('Kurzname')->maxLength(120),
                Toggle::make('is_active')->label('Aktiv')->default(true)->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category'))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(ArticleCategory::query()->select('sort_order')->whereColumn('article_categories.id', 'articles.category_id'))
                ->orderBy('name'))
            ->columns([
                TextColumn::make('category.name')->label('Kategorie')->badge()->color('gray'),
                TextColumn::make('name')->label('Artikel')->searchable(),
                TextColumn::make('unit')->label('Einheit')->placeholder('–'),
                TextColumn::make('price')->label('Preis')->money('EUR', locale: 'de')->alignEnd(),
                IconColumn::make('is_active')->label('Aktiv')->boolean()->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Kategorie')->relationship('category', 'name'),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
                // Nur nie bestellte Artikel, siehe ArticlePolicy
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArticles::route('/'),
        ];
    }
}
