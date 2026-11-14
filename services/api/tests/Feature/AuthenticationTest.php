<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_credentials_are_not_a_parallel_authentication_path(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|forged-fixture')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_totp_login_requires_a_real_second_factor_and_logout_revokes_access(): void
    {
        $engine = new Google2FA;
        $secret = $engine->generateSecretKey();
        $user = User::factory()->create([
            'roles' => ['catalogue'],
            'password' => 'LongPassword1234',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-fixture-1'])),
        ]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'LongPassword1234'])
            ->assertOk()
            ->assertJsonPath('two_factor', true);
        $this->assertGuest();
        $this->getJson('/api/v1/operations/content/scenes')->assertUnauthorized();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $engine->getCurrentOtp($secret)])
            ->assertOk()
            ->assertSessionHas('mfa_verified', true);
        $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/v1/operations/content/scenes')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        // Le processus PHPUnit conserve les guards ; une nouvelle requête HTTP les reconstruit.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/operations/content/scenes')->assertUnauthorized();
    }

    public function test_recovery_code_is_single_use_and_cannot_disable_staff_mfa(): void
    {
        $engine = new Google2FA;
        $user = User::factory()->create([
            'roles' => ['owner'],
            'password' => 'LongPassword1234',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($engine->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['single-use-fixture'])),
        ]);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'LongPassword1234',
        ])->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'single-use-fixture'])->assertOk();
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'LongPassword1234'])->assertCreated();
        $this->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertForbidden();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'LongPassword1234',
        ])->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'recovery_code' => 'single-use-fixture',
        ])->assertUnprocessable();
    }

    public function test_first_enrollment_can_retrieve_recovery_codes_but_still_requires_a_new_mfa_login(): void
    {
        $user = User::factory()->create(['roles' => ['owner'], 'password' => 'LongPassword1234']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'LongPassword1234'])->assertOk();
        $this->postJson('/api/v1/auth/user/confirm-password', ['password' => 'LongPassword1234'])->assertCreated();
        $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
        $secret = $this->getJson('/api/v1/auth/user/two-factor-secret-key')->assertOk()->json('secretKey');
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertOk();
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8);
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertForbidden();
        $this->withHeader('Origin', 'http://localhost:3000')
            ->getJson('/api/v1/operations/content/scenes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_wrong_password_has_no_authenticated_session(): void
    {
        $user = User::factory()->create(['password' => 'LongPassword1234']);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1234',
        ])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_password_recovery_has_the_same_response_for_known_and_unknown_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_password_reset_preserves_mfa_and_revokes_existing_database_sessions(): void
    {
        $user = User::factory()->create([
            'roles' => ['owner'],
            'password' => 'OldPassword1234',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_confirmed_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-fixture',
            'user_id' => $user->id,
            'payload' => 'fixture',
            'last_activity' => time(),
        ]);
        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword1234',
            'password_confirmation' => 'NewPassword1234',
        ])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'session-fixture']);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertTrue(Hash::check('NewPassword1234', $user->fresh()->password));
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword1234',
        ])->assertJsonPath('two_factor', true);
    }

    public function test_credentials_and_mfa_secrets_are_absent_from_me_response(): void
    {
        $user = User::factory()->create(['roles' => ['client'], 'two_factor_secret' => 'encrypted-fixture']);
        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:4321')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.two_factor_secret')
            ->assertJsonMissingPath('data.roles');
    }
}
