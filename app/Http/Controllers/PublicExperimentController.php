<?php

namespace App\Http\Controllers;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PublicExperimentController extends Controller
{
    public function show(string $shareToken): View|RedirectResponse
    {
        $experiment = Experiment::withoutGlobalScopes()
            ->where('share_token', $shareToken)
            ->where('share_enabled', true)
            ->firstOrFail();

        if ($experiment->isShareExpired()) {
            abort(404, 'This share link has expired.');
        }

        $config = array_merge([
            'show_costs' => false,
            'show_stages' => true,
            'show_outputs' => true,
            'expires_at' => null,
        ], $experiment->share_config ?? []);

        $stages = collect();
        if ($config['show_stages']) {
            $stages = $experiment->stages()
                ->orderBy('order')
                ->get(['id', 'type', 'status', 'started_at', 'completed_at', 'output']);
        }

        return view('public.experiment', compact('experiment', 'config', 'stages'));
    }
}
