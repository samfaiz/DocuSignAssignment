<?php

namespace Tests\Feature;

use App\Models\MailSetting;
use App\Models\User;
use App\Services\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailSettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'sender@example.com',
            'password' => 'abcd efgh ijkl mnop',
            'encryption' => 'tls',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'SignDesk',
        ], $overrides);
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/settings/mail')->assertUnauthorized();
        $this->putJson('/api/settings/mail', $this->validPayload())->assertUnauthorized();
        $this->postJson('/api/settings/mail/test', ['to' => 'a@b.test'])->assertUnauthorized();
    }

    public function test_an_admin_can_save_settings(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings/mail', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('settings.host', 'smtp.gmail.com')
            ->assertJsonPath('settings.is_configured', true);
    }

    public function test_the_password_is_never_returned(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/settings/mail', $this->validPayload())->assertOk();

        $response = $this->getJson('/api/settings/mail')->assertOk();

        $this->assertStringNotContainsString('abcd efgh ijkl mnop', $response->getContent());
        // The UI is told a password exists, never what it is.
        $this->assertTrue($response->json('settings.has_password'));
        $this->assertArrayNotHasKey('password', $response->json('settings'));
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/settings/mail', $this->validPayload())->assertOk();

        // Read the raw column, bypassing the model cast.
        $stored = DB::table('mail_settings')->value('password');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('abcd efgh ijkl mnop', $stored);
        // Round-trips back through the cast.
        $this->assertSame('abcd efgh ijkl mnop', MailSetting::current()->password);
    }

    public function test_an_omitted_password_keeps_the_stored_one(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/settings/mail', $this->validPayload())->assertOk();

        // Change the host without retyping a password the admin cannot read back.
        $this->putJson('/api/settings/mail', $this->validPayload([
            'host' => 'smtp-relay.brevo.com',
            'password' => '',
        ]))->assertOk();

        $settings = MailSetting::current();
        $this->assertSame('smtp-relay.brevo.com', $settings->host);
        $this->assertSame('abcd efgh ijkl mnop', $settings->password);
    }

    public function test_settings_are_validated(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings/mail', $this->validPayload(['port' => 99999]))
            ->assertStatus(422);

        $this->putJson('/api/settings/mail', $this->validPayload(['from_address' => 'not-an-email']))
            ->assertStatus(422);

        $this->putJson('/api/settings/mail', $this->validPayload(['encryption' => 'rot13']))
            ->assertStatus(422);
    }

    public function test_saved_settings_are_applied_over_the_config(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/settings/mail', $this->validPayload())->assertOk();

        MailConfigurator::forget();
        MailConfigurator::apply();

        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('no-reply@example.com', config('mail.from.address'));
    }

    public function test_testing_before_configuring_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/settings/mail/test', ['to' => 'someone@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
