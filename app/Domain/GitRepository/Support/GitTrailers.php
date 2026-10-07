<?php

namespace App\Domain\GitRepository\Support;

final class GitTrailers
{
    /**
     * Append `Key: value` trailers to a commit message, separated from the
     * body by one blank line. Keys already present are not duplicated.
     *
     * @param  array<string, string>  $trailers
     */
    public static function append(string $message, array $trailers): string
    {
        if ($trailers === []) {
            return $message;
        }

        $message = rtrim($message);

        $lines = [];
        foreach ($trailers as $key => $value) {
            if (preg_match('/^'.preg_quote((string) $key, '/').'\s*:/mi', $message) === 1) {
                continue;
            }
            $lines[] = $key.': '.$value;
        }

        if ($lines === []) {
            return $message;
        }

        // Join to an existing trailer block directly; otherwise start a new one.
        $lastBlock = preg_split('/\R\R/', $message);
        $last = (string) end($lastBlock);
        $joinsBlock = count($lastBlock) > 1
            && preg_match('/\A(?:[A-Za-z][A-Za-z0-9-]*:\s.*(?:\R|\z))+\z/', $last."\n") === 1;

        return $message.($joinsBlock ? "\n" : "\n\n").implode("\n", $lines);
    }
}
