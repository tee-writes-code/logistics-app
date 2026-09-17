<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->customer()->create(['email' => 'casey@logistics.test']);

        // The SPA obtains a CSRF cookie via /sanctum/csrf-cookie first; here we
        // exercise the login logic itself with the CSRF check disabled. Origin
        // marks the request as stateful so a session is started.
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'email' => 'casey@logistics.test',
                'password' => 'password',
            ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->customer()->create(['email' => 'casey@logistics.test']);

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [
                'email' => 'casey@logistics.test',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_fetch_themselves(): void
    {
        $user = User::factory()->ops()->create();

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_logout_returns_no_content(): void
    {
        $user = User::factory()->rider()->create();

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertNoContent();
    }
}
