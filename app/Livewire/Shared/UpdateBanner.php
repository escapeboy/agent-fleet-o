<?php

namespace App\Livewire\Shared;

use App\Domain\System\Services\VersionCheckService;
use Livewire\Component;

class UpdateBanner extends Component
{
    public bool $dismissed = false;

    public function mount(): void
    {
        $dismissedVersion = session('update_banner_dismissed');
        $service = app(VersionCheckService::class);

        if ($dismissedVersion && ! $service->isUpdateAvailable()) {
            $this->dismissed = true;

            return;
        }

        $info = $service->getUpdateInfo();
        if ($dismissedVersion && $info && $dismissedVersion === $info['version']) {
            $this->dismissed = true;
        }
    }

    public function dismiss(): void
    {
        $info = app(VersionCheckService::class)->getUpdateInfo();
        session(['update_banner_dismissed' => $info['version'] ?? true]);
        $this->dismissed = true;
    }

    public function render()
    {
        $service = app(VersionCheckService::class);

        return view('livewire.shared.update-banner', [
            'updateAvailable' => ! $this->dismissed && $service->isUpdateAvailable(),
            'updateInfo' => $service->getUpdateInfo(),
        ]);
    }
}
