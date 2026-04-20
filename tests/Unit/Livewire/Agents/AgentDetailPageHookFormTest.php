<?php

namespace Tests\Unit\Livewire\Agents;

use App\Livewire\Agents\AgentDetailPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression test for the hook form Cancel → 500 bug.
 *
 * The Cancel button used wire:click="$call('resetHookForm')" (invalid Livewire
 * syntax; $call is not a magic action) AND the target method was declared
 * private (not callable from the frontend). Either mistake alone produced a
 * 500 on the agent detail page every time the user opened + cancelled the
 * "Add Hook" form.
 */
class AgentDetailPageHookFormTest extends TestCase
{
    public function test_reset_hook_form_is_a_public_method(): void
    {
        $reflection = new ReflectionMethod(AgentDetailPage::class, 'resetHookForm');

        $this->assertTrue(
            $reflection->isPublic(),
            'resetHookForm() must be public so wire:click can invoke it from the Cancel button.',
        );
    }

    public function test_reset_hook_form_takes_no_required_arguments(): void
    {
        $reflection = new ReflectionMethod(AgentDetailPage::class, 'resetHookForm');

        $this->assertSame(
            0,
            $reflection->getNumberOfRequiredParameters(),
            'resetHookForm() must be callable via wire:click without arguments.',
        );
    }
}
