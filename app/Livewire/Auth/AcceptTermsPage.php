<?php

namespace App\Livewire\Auth;

use App\Domain\Shared\Services\TermsAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.auth')]
class AcceptTermsPage extends Component
{
    public int $version;

    public string $ipAddress = '';

    public string $userAgent = '';

    public function boot(Request $request): void
    {
        $this->ipAddress = $request->ip() ?? '';
        $this->userAgent = mb_substr($request->userAgent() ?? '', 0, 500);
    }

    public function mount(): void
    {
        $this->version = (int) config('terms.current_version');

        // Already accepted — redirect to dashboard
        if (! app(TermsAcceptanceService::class)->requiresAcceptance(Auth::user())) {
            $this->redirect(route('dashboard'));
        }
    }

    public function accept(Request $request): void
    {
        app(TermsAcceptanceService::class)->record(
            Auth::user(),
            $request,
            'post_login',
        );

        $intended = redirect()->intended(route('dashboard'));

        $this->redirect($intended->getTargetUrl());
    }

    public function decline(): void
    {
        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $this->redirect(route('login'));
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.auth.accept-terms-page');
    }
}
