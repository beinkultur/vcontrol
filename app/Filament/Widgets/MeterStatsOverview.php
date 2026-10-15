<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsMeter;
use App\Support\MeterStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Kennzahlen eines Zählers im Jahr: Verbrauch (gegen dieselben Tage im
 * Vorjahr), bei Zählern je Event der Anteil der Events und der Verbrauch je
 * PAX, sonst der Schnitt je Tag; dazu der letzte Stand.
 */
class MeterStatsOverview extends StatsOverviewWidget
{
    use ShowsMeter;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public function mount(): void
    {
        $this->authorizeMeter();
    }

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $meter = $this->meter();
        $stats = $this->meterStats();
        if ($meter === null || $stats === null) {
            return [];
        }
        $unit = $meter->unit;
        $year = $stats->year($this->year);

        $change = MeterStats::change($year['amount'], $year['previous']);
        $total = Stat::make('Verbrauch ' . $this->year, MeterStats::amount($year['amount'], $unit))
            ->description(match (true) {
                $year['amount'] === null => 'Noch keine zwei Stände in diesem Jahr',
                $change !== null => $change . ' gegen ' . ($this->year - 1) . ' (' . MeterStats::amount($year['previous'], $unit) . ', ' . $this->period($year) . ')',
                default => $this->period($year) . ' · ohne Vergleich zum Vorjahr',
            });
        // Mehr Verbrauch orange, weniger grün; ±0 % ohne Wertung
        if ($change !== null && !str_starts_with($change, '±')) {
            $up = $year['amount'] > $year['previous'];
            $total->descriptionIcon($up ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->color($up ? 'warning' : 'success');
        }

        $result = [$total];
        if ($meter->per_event) {
            $events = $stats->events($this->year);
            $sum = array_sum(array_column($events, 'amount'));
            $withPax = array_filter($events, fn (array $row): bool => $row['pax'] !== null);
            $pax = array_sum(array_column($withPax, 'pax'));
            $share = $events !== [] && $year['amount'] !== null && $year['amount'] > 0 ? (int) round($sum / $year['amount'] * 100) : null;
            $result[] = Stat::make('Bei Events', $events === [] ? '–' : MeterStats::amount($sum, $unit))
                ->description($events === [] ? 'Noch kein Event mit Stand Anfang und Ende'
                    : count($events) . ' ' . (count($events) === 1 ? 'Event' : 'Events') . ' · Ø ' . MeterStats::amount($sum / count($events), $unit)
                        . ($share !== null ? ' · ' . min($share, 100) . ' % des Verbrauchs' : ''));
            $result[] = Stat::make('Je PAX', $pax > 0 ? MeterStats::amount(array_sum(array_column($withPax, 'amount')) / $pax, $unit) : '–')
                ->description($pax > 0 ? 'bei ' . number_format($pax, 0, ',', '.') . ' PAX abgerechnet' : 'PAX abgerechnet fehlen noch');
        } else {
            $result[] = Stat::make('Ø je Tag', MeterStats::amount($year['perDay'], $unit))
                ->description($year['perDay'] !== null ? $this->period($year) : 'Noch keine zwei Stände in diesem Jahr');
        }

        $latest = $stats->latest();
        $result[] = Stat::make('Letzter Stand', $latest === null ? '–' : MeterStats::reading($latest->value, $unit))
            ->description($latest === null ? 'Noch nichts erfasst' : $latest->read_at->format('d.m.Y') . ' · ' . $latest->sourceLabel());

        return $result;
    }

    /** „01.01.–12.10.“ – die Tage, für die es Stände gibt */
    private function period(array $year): string
    {
        return $year['from'] === null ? '' : $year['from']->format('d.m.') . '–' . $year['until']->format('d.m.');
    }
}
