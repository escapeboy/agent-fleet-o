<?php

namespace App\Domain\Outbound\Actions;

use App\Domain\Outbound\Models\OutboundProposal;
use App\Models\Blacklist;

class CheckBlacklistAction
{
    /**
     * Check if the proposal's target or content matches any blacklist entry.
     *
     * @return array{blocked: bool, reason: ?string}
     */
    public function execute(OutboundProposal $proposal): array
    {
        $target = $proposal->target;
        $content = $proposal->content;

        // Check email blacklist
        $email = is_array($target) ? ($target['email'] ?? null) : (is_string($target) ? $target : null);
        if ($email && Blacklist::where('type', 'email')->where('value', $email)->exists()) {
            return ['blocked' => true, 'reason' => "Email blacklisted: {$email}"];
        }

        // Check domain blacklist
        if ($email) {
            $domain = substr($email, strpos($email, '@') + 1);
            if ($domain && Blacklist::where('type', 'domain')->where('value', $domain)->exists()) {
                return ['blocked' => true, 'reason' => "Domain blacklisted: {$domain}"];
            }
        }

        // Check company blacklist
        $company = is_array($target) ? ($target['company'] ?? null) : null;
        if ($company && Blacklist::where('type', 'company')->whereRaw('LOWER(value) = ?', [strtolower($company)])->exists()) {
            return ['blocked' => true, 'reason' => "Company blacklisted: {$company}"];
        }

        // Check keyword blacklist in content
        $contentText = is_array($content) ? json_encode($content) : (string) $content;
        $keywords = Blacklist::where('type', 'keyword')->pluck('value');

        foreach ($keywords as $keyword) {
            if (stripos($contentText, $keyword) !== false) {
                return ['blocked' => true, 'reason' => "Content contains blacklisted keyword: {$keyword}"];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }
}
