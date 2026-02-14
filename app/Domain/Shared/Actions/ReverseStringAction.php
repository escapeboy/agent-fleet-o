<?php

namespace App\Domain\Shared\Actions;

use InvalidArgumentException;

class ReverseStringAction
{
    /**
     * Reverse a string while preserving multibyte (UTF-8) characters.
     *
     * @param  string  $input  The string to reverse.
     * @return string The reversed string.
     *
     * @throws InvalidArgumentException If the input contains invalid UTF-8.
     */
    public function execute(string $input): string
    {
        if ($input === '') {
            return '';
        }

        if (! mb_check_encoding($input, 'UTF-8')) {
            throw new InvalidArgumentException('Input must be valid UTF-8.');
        }

        $characters = mb_str_split($input);

        return implode('', array_reverse($characters));
    }
}
