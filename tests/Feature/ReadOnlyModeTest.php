<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ReadOnlyMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The deploy window (docs/zero-downtime-deploy.md): reads keep working, writes get 503 + Retry-After. */
class ReadOnlyModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ReadOnlyMode::disable();
        parent::tearDown();
    }

    public function test_reads_keep_working_and_writes_are_refused_while_read_only(): void
    {
        $user = User::factory()->create();
        ReadOnlyMode::enable();

        $this->get('/about')->assertOk();
        $this->actingAs($user)->get('/dashboard/connections')->assertOk();

        $this->actingAs($user)->postJson('/dashboard/connections', ['id' => 'x'])
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) ReadOnlyMode::RETRY_AFTER_SECONDS);
        $this->actingAs($user)->patch('/settings', [])
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) ReadOnlyMode::RETRY_AFTER_SECONDS);
    }

    public function test_read_only_posts_are_allowed_through(): void
    {
        ReadOnlyMode::enable();

        $this->postJson('/login/lookup', ['identifier' => 'nobody@example.com'])->assertStatus(200);
    }

    public function test_writes_work_again_once_the_flag_is_lowered(): void
    {
        $user = User::factory()->create();
        ReadOnlyMode::enable();
        $this->actingAs($user)->postJson('/dashboard/connections', ['id' => 'x'])->assertStatus(503);

        ReadOnlyMode::disable();

        // Validation failure (422), not 503: the request reached the handler.
        $this->actingAs($user)->postJson('/dashboard/connections', ['id' => 'x'])->assertStatus(422);
    }

    public function test_the_deploy_scripts_flag_file_is_the_one_the_app_honours(): void
    {
        // deploy.conf raises the flag with a plain `touch storage/framework/readonly`.
        $this->assertSame(storage_path('framework/readonly'), ReadOnlyMode::path());
        touch(ReadOnlyMode::path());

        $this->assertTrue(ReadOnlyMode::isActive());
        $this->artisan('app:readonly', ['state' => 'off'])->assertSuccessful();
        $this->assertFalse(ReadOnlyMode::isActive());
    }

    public function test_status_reports_dependency_health_even_while_read_only(): void
    {
        ReadOnlyMode::enable();

        $this->getJson('/status')->assertOk()->assertJson(['status' => 'ok', 'checks' => ['database' => true, 'cache' => true]]);
    }
}
