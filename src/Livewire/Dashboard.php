<?php

namespace LogInsight\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use LogInsight\Metrics\MetricsService;
use LogInsight\Query\LogQuery;

#[Layout('log-insight::layout')]
class Dashboard extends Component
{
    use GuardsAccess;
    use RefreshesLogs;

    #[Url]
    public string $period = 'month';

    public function mount(): void
    {
        $this->authorizeAbility('view');
        $this->refreshLogs();
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, LogQuery::PRESETS)) {
            $this->period = 'month';
        }
    }

    public function render(MetricsService $metrics)
    {
        $this->authorizeAbility('view');

        $period = array_key_exists($this->period, LogQuery::PRESETS) ? $this->period : 'month';
        [$from, $to] = LogQuery::resolvePreset($period);
        $hourly = in_array($period, ['15m', '1h', '24h', 'today', 'yesterday'], true);
        // Never chart into the future.
        $chartTo = $to->gt(now()) ? now() : $to;

        return view('log-insight::livewire.dashboard', [
            'kpis' => $metrics->kpis(),
            'timeline' => $metrics->timeline($from, $chartTo, $hourly ? 'hour' : 'day'),
            'hourly' => $hourly,
            'levels' => $metrics->byLevel($from, $to),
            'tags' => $metrics->topTags($from, $to),
            'topIssues' => $metrics->topIssues($from, $to),
            'newIssues' => $metrics->newIssues($from, $to),
            'regressions' => $metrics->regressions(),
            'from' => $from,
            'to' => $to,
            'periods' => LogQuery::PRESETS,
            'errorLevels' => $metrics->errorLevels(),
            'activePeriod' => $period,
            'pollSeconds' => $this->pollSeconds(),
        ]);
    }
}
