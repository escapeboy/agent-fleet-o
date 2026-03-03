<?php

namespace App\Livewire\Changelog;

use App\Domain\System\Services\ChangelogParser;
use Livewire\Component;

class ChangelogPage extends Component
{
    public function mount(): void
    {
        auth()->user()?->update(['changelog_seen_at' => now()]);
    }

    public function render()
    {
        $parser = app(ChangelogParser::class);

        return view('livewire.changelog.changelog-page', [
            'entries' => $parser->parse(),
        ])->layout('layouts.app', ['header' => "What's New"]);
    }
}
