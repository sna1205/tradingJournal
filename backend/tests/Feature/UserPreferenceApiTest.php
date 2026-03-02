<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user/preferences')->assertUnauthorized();
    }

    public function test_get_preferences_creates_default_row_for_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/preferences');

        $response->assertOk();
        $response->assertJsonPath('user_id', (int) $user->id);
        $response->assertJsonPath('theme_mode', 'light');
        $response->assertJsonPath('profile_timezone', 'UTC');
        $response->assertJsonPath('profile_locale', 'en-US');
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => (int) $user->id,
            'theme_mode' => 'light',
            'profile_timezone' => 'UTC',
            'profile_locale' => 'en-US',
        ]);
    }

    public function test_update_preferences_validates_payload_and_persists_changes_with_audit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/user/preferences', [
            'theme_mode' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['theme_mode']);

        $this->putJson('/api/user/preferences', [
            'profile_timezone' => '***',
        ])->assertStatus(422)->assertJsonValidationErrors(['profile_timezone']);

        $response = $this->putJson('/api/user/preferences', [
            'theme_mode' => 'forest',
            'profile_timezone' => 'America/New_York',
            'profile_locale' => 'en-US',
        ]);

        $response->assertOk();
        $response->assertJsonPath('theme_mode', 'forest');
        $response->assertJsonPath('profile_timezone', 'America/New_York');
        $response->assertJsonPath('profile_locale', 'en-US');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => (int) $user->id,
            'theme_mode' => 'forest',
            'profile_timezone' => 'America/New_York',
            'profile_locale' => 'en-US',
        ]);

        $audit = DB::table('user_preference_audits')
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $user->id, (int) $audit->changed_by_user_id);

        $changedKeys = json_decode((string) $audit->changed_keys, true, 512, JSON_THROW_ON_ERROR);
        $beforeValues = json_decode((string) $audit->before_values, true, 512, JSON_THROW_ON_ERROR);
        $afterValues = json_decode((string) $audit->after_values, true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains('theme_mode', $changedKeys);
        $this->assertContains('profile_timezone', $changedKeys);
        $this->assertSame('light', $beforeValues['theme_mode']);
        $this->assertSame('forest', $afterValues['theme_mode']);
        $this->assertSame('UTC', $beforeValues['profile_timezone']);
        $this->assertSame('America/New_York', $afterValues['profile_timezone']);
    }
}
