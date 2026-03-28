<?php

namespace App\Domain\Signal\Rules;

use App\Domain\Signal\Contracts\EntityRule;
use App\Domain\Signal\DTOs\ContactRiskContext;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;

class E01DisposableEmailRule extends EntityRule
{
    public function name(): string
    {
        return 'E01';
    }

    public function label(): string
    {
        return 'Disposable email domain';
    }

    public function weight(): int
    {
        return 20;
    }

    public function evaluate(ContactRiskContext $context): bool
    {
        $email = $context->contact->email;

        if ($email === null) {
            return false;
        }

        return DisposableDomains::isDisposable($email);
    }
}
