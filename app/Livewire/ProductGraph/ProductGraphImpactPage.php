<?php

namespace App\Livewire\ProductGraph;

use App\Domain\ProductGraph\Models\ProductNode;
use App\Domain\ProductGraph\Services\ImpactAnalyzer;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductGraphImpactPage extends Component
{
    #[Url]
    public ?string $nodeId = null;

    public function selectNode(string $id): void
    {
        $this->nodeId = $id;
    }

    private function teamId(): string
    {
        return auth()->user()->current_team_id;
    }

    public function render()
    {
        if (! config('productgraph.enabled')) {
            return view('livewire.product-graph.product-graph-impact-page', ['disabled' => true]);
        }

        $teamId = $this->teamId();

        $nodes = ProductNode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('node_type')->orderBy('name')
            ->get();

        $selected = null;
        $impact = [];

        if ($this->nodeId !== null) {
            $selected = ProductNode::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($this->nodeId);

            if ($selected) {
                $impact = app(ImpactAnalyzer::class)->blastRadius($selected);
            }
        }

        return view('livewire.product-graph.product-graph-impact-page', [
            'disabled' => false,
            'nodes' => $nodes,
            'selected' => $selected,
            'impact' => collect($impact)->groupBy('depth'),
        ]);
    }
}
