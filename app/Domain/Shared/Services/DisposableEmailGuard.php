<?php

namespace App\Domain\Shared\Services;

class DisposableEmailGuard
{
    /** @var array<int, string> */
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.de',
        'tempmail.com',
        'temp-mail.org',
        'temp-mail.io',
        '10minutemail.com',
        '10minutemail.net',
        'throwaway.email',
        'throwam.com',
        'trashmail.com',
        'trashmail.me',
        'trashmail.net',
        'trashmail.io',
        'yopmail.com',
        'yopmail.fr',
        'sharklasers.com',
        'guerrillamailblock.com',
        'grr.la',
        'spam4.me',
        'mailnull.com',
        'mailnesia.com',
        'dispostable.com',
        'discard.email',
        'spamgourmet.com',
        'spamgourmet.net',
        'fakeinbox.com',
        'getairmail.com',
        'filzmail.com',
        'owlpic.com',
        'maildrop.cc',
        'getnada.com',
        'mohmal.com',
        'mytemp.email',
        'inboxbear.com',
        'tempr.email',
        'tempinbox.com',
        'safetymail.info',
        'spamex.com',
        'nwldx.com',
        'mailtemp.net',
        'tempmail.ninja',
        'spamfree24.org',
        'zzrgg.com',
        'binkmail.com',
        'bobmail.info',
        'clrmail.com',
        'einrot.com',
    ];

    public function isDisposable(string $email): bool
    {
        $parts = explode('@', strtolower(trim($email)), 2);

        if (count($parts) !== 2 || $parts[1] === '') {
            return false;
        }

        return in_array($parts[1], self::DISPOSABLE_DOMAINS, strict: true);
    }

    public function assertNotDisposable(string $email): void
    {
        if ($this->isDisposable($email)) {
            throw new \InvalidArgumentException(
                'Disposable or temporary email addresses are not allowed.',
            );
        }
    }
}
