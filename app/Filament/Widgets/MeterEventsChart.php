<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsMeter;
use App\Support\MeterStats;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Str;

/** Verbrauch je Event im Jahr (Zähler „je Event“), nach Datum. */
class MeterEventsChart extends ChartWidget
{
    use ShowsMeter;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    protected ?string $emptyStateHeading = 'Noch keine Events';

    protected ?string $emptyStateDescription = 'Hier erscheinen Events mit Stand Anfang und Ende.';

    public function mount(): void
    {
        $this->authorizeMeter();
        parent::mount();
    }

    public function getHeading(): string
    {
        return 'Verbrauch je Event' . (($unit = $this->meter()?->unit) ? ' in ' . $unit : '');
    }

    public function getDescription(): string
    {
        return 'Stand Ende minus Stand Anfang, Events ' . $this->year . '.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $events = $this->meterStats()?->events($this->year) ?? [];
        if ($events === []) {
            return [];
        }

        return [
            'datasets' => [
                ['label' => 'Verbrauch', 'data' => array_map(fn (array $row): ?float => MeterStats::rounded($row['amount']), $events)],
            ],
            'labels' => array_map(fn (array $row): string => $row['event']->starts_at->format('d.m.') . ' ' . Str::limit((string) $row['event']->title, 16), $events),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
