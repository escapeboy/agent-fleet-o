<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class ResetPasswordPageTest extends TestCase
{
    public function test_reset_password_link_renders_form_with_token_and_email(): void
    {
        $response = $this->get('/reset-password/test-token-123?email=user%40example.com');

        $response->assertOk();
        $response->assertSee('name="token" value="test-token-123"', false);
        $response->assertSee('user@example.com', false);
    }

    public function test_reset_password_link_without_email_still_renders(): void
    {
        $this->get('/reset-password/test-token-123')->assertOk();
    }
}
