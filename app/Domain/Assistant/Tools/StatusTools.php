<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Models\GlobalSetting;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class StatusTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::getBudgetSummary(),
            self::getDashboardKpis(),
            self::getSystemHealth(),
        ];
    }

    private static function getBudgetSummary(): PrismToolObject
    {
        return PrismTool::as('get_budget_summary')
            ->for('Get the current team budget summary including total spent, cap, and remaining credits')
            ->using(function () {
                $globalCap = GlobalSetting::get('global_budget_cap', 100000);

                $totalSpent = CreditLedger::sum('amount');
                $totalReserved = CreditLedger::where('type', 'reservation')->sum('amount');

                return json_encode([
                    'global_budget_cap' => $globalCap,
                    'total_spent' => abs($totalSpent),
                    'total_reserved' => abs($totalReserved),
                    'remaining' => $globalCap - abs($totalSpent),
                    'utilization_pct' => $globalCap > 0 ? round(abs($totalSpent) / $globalCap * 100, 1) : 0,
                ]);
            });
    }

    private static function getDashboardKpis(): PrismToolObject
    {
        return PrismTool::as('get_dashboard_kpis')
            ->for('Get key performance indicators: experiment counts by status, project counts, agent counts')
            ->using(function () {
                return json_encode([
                    'experiments' => [
                        'total' => Experiment::count(),
                        'running' => Experiment::where('status', 'running')->count(),
                        'completed' => Experiment::where('status', 'completed')->count(),
                        'failed' => Experiment::where('status', 'failed')->count(),
                        'paused' => Experiment::where('status', 'paused')->count(),
                    ],
                    'projects' => [
                        'total' => Project::count(),
                        'active' => Project::where('status', 'active')->count(),
                    ],
                    'agents' => [
                        'total' => Agent::count(),
                        'active' => Agent::where('status', 'active')->count(),
                    ],
                ]);
            });
    }

    private static function getSystemHealth(): PrismToolObject
    {
        return PrismTool::as('get_system_health')
            ->for('Get system health status including queue, database, and cache connectivity')
            ->using(function () {
                $health = [];

                // Database check
                try {
                    \DB::select('SELECT 1');
                    $health['database'] = 'ok';
                } catch (\Throwable) {
                    $health['database'] = 'error';
                }

                // Redis/Cache check
                try {
                    \Cache::store('redis')->put('health_check', true, 10);
                    $health['cache'] = \Cache::store('redis')->get('health_check') ? 'ok' : 'error';
                } catch (\Throwable) {
                    $health['cache'] = 'error';
                }

                // Queue check (Horizon)
                try {
                    $horizonStatus = app('horizon.status')->current();
                    $health['queue'] = $horizonStatus === 'running' ? 'ok' : $horizonStatus;
                } catch (\Throwable) {
                    $health['queue'] = 'unknown';
                }

                return json_encode($health);
            });
    }
}
