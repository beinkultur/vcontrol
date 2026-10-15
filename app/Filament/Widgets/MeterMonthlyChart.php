<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsMeter;
use App\Support\MeterStats;
use Filament\Widgets\ChartWidget;

/** Verbrauch je Monat, das gewählte Jahr gegen das Vorjahr (Balken). */
class MeterMonthlyChart extends ChartWidget
{
    use ShowsMeter;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    protected ?string $emptyStateHeading = 'Noch keine Werte';

    protected ?string $emptyStateDescription = 'Der Verbrauch ergibt sich aus mindestens zwei Ständen.';

    public function mount(): void
    {
        $this->authorizeMeter();
        parent::mount();
    }

    public function getHeading(): string
    {
        return 'Verbrauch je Monat' . (($unit = $this->meter()?->unit) ? ' in ' . $unit : '');
    }

    public function getDescription(): string
    {
        // Angebrochene Monate nennen: „Okt nur bis 13.10.“
        $stats = $this->meterStats();
        $partial = collect(range(1, 12))
            ->map(fn (int $month): ?string => ($note = $stats?->partialMonth($this->year, $month)) !== null ? MeterStats::MONTHS[$month - 1] . ' nur ' . $note : null)
            ->filter();

        return $this->year . ' gegen ' . ($this->year - 1) . ($partial->isNotEmpty() ? ' (' . $partial->implode(', ') . ')' : '')
            . '. Zwischen zwei Ständen verteilt sich der Verbrauch gleichmäßig auf die Tage.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $stats = $this->meterStats();
        if ($stats === null) {
            return [];
        }
        $current = array_map(MeterStats::rounded(...), array_values($stats->months($this->year)));
        $previous = array_map(MeterStats::rounded(...), array_values($stats->months($this->year - 1)));
        if (array_filter([...$current, ...$previous], fn (?float $value): bool => $value !== null) === []) {
            return [];
        }

        return [
            'datasets' => [
                ['label' => (string) $this->year, 'data' => $current],
                // Vorjahr zurückhaltend grau, in hell und dunkel lesbar
                ['label' => (string) ($this->year - 1), 'data' => $previous, 'backgroundColor' => 'rgb(148 163 184 / 0.18)', 'borderColor' => 'rgb(148 163 184 / 0.75)'],
            ],
            'labels' => MeterStats::MONTHS,
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
