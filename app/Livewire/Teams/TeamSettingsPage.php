<?php

namespace App\Livewire\Teams;

use App\Domain\Bridge\Actions\TerminateBridgeConnection;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Telegram\Actions\RegisterTelegramBotAction;
use App\Domain\Telegram\Models\TelegramBot;
use App\Infrastructure\AI\Services\LocalLlmUrlValidator;
use App\Models\GlobalSetting;
use LaravelWebauthn\Models\WebauthnKey;
use LaravelWebauthn\WebauthnServiceProvider;