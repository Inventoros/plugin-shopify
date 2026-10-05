<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Inventoros\Plugins\Shopify\Jobs\RecordActivity;
use Inventoros\Plugins\Shopify\Support\Permissions;
use Inventoros\Plugins\Shopify\Support\Settings;
use Inventoros\Plugins\Shopify\Tests\TestCase;

final class SettingsPageTest extends TestCase
{
    public function test_an_admin_sees_the_settings_page_with_defaults(): void
    {
        $this->actingAs($this->admin)
            ->get('/shopify/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plugin::shopify/Settings')
                ->where('settings.greeting', Settings::DEFAULTS['greeting'])
                ->where('settings.log_low_stock', false)
                ->where('activity', [])
                ->where('updateUrl', route('shopify.settings.update')));
    }

    public function test_a_member_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->member)->get('/shopify/settings')->assertForbidden();
        $this->actingAs($this->member)
            ->put('/shopify/actions/settings', ['greeting' => 'Hi', 'log_low_stock' => true])
            ->assertForbidden();
    }

    public function test_a_member_granted_the_permission_can_manage_settings(): void
    {
        $this->grantToMembers(Permissions::manage());

        $this->actingAs($this->member)->get('/shopify/settings')->assertOk();
        $this->actingAs($this->member)
            ->from('/shopify/settings')
            ->put('/shopify/actions/settings', ['greeting' => 'Hi team', 'log_low_stock' => true])
            ->assertRedirect('/shopify/settings')
            ->assertSessionHas('success');

        $this->assertSame('Hi team', Settings::for($this->organization->id)->get('greeting'));
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/shopify/settings')->assertRedirect(route('login'));
        $this->put('/shopify/actions/settings', [])->assertRedirect(route('login'));
    }

    public function test_saving_validates_and_queues_an_activity_line(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)
            ->put('/shopify/actions/settings', ['greeting' => str_repeat('x', 121), 'log_low_stock' => 'maybe'])
            ->assertSessionHasErrors(['greeting', 'log_low_stock']);

        Queue::assertNothingPushed();

        $this->actingAs($this->admin)
            ->put('/shopify/actions/settings', ['greeting' => 'Welcome', 'log_low_stock' => true])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(RecordActivity::class, fn (RecordActivity $job) => $job->organizationId === $this->organization->id
            && $job->type === 'settings');
    }

    public function test_settings_are_isolated_per_organization(): void
    {
        $other = $this->makeOrganization('Globex');
        $otherAdmin = $this->makeUser($other, 'admin');

        $this->actingAs($this->admin)
            ->put('/shopify/actions/settings', ['greeting' => 'Acme only', 'log_low_stock' => true]);

        $this->actingAs($otherAdmin)
            ->get('/shopify/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.greeting', Settings::DEFAULTS['greeting'])
                ->where('settings.log_low_stock', false));

        $this->assertSame('Acme only', Settings::for($this->organization->id)->get('greeting'));
    }

    public function test_the_activity_list_only_shows_the_viewers_organization(): void
    {
        $other = $this->makeOrganization('Initech');

        (new RecordActivity($this->organization->id, 'note', 'Ours'))->handle();
        (new RecordActivity($other->id, 'note', 'Theirs'))->handle();

        $this->actingAs($this->admin)
            ->get('/shopify/settings')
            ->assertInertia(fn ($page) => $page
                ->has('activity', 1)
                ->where('activity.0.message', 'Ours'));
    }

    public function test_the_menu_item_is_shared_only_with_users_who_may_open_the_page(): void
    {
        $labels = fn ($user) => collect(
            $this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['pluginMenuItems']
        )->pluck('label')->all();

        $this->assertContains('Shopify', $labels($this->admin));
        $this->assertNotContains('Shopify', $labels($this->member));
    }
}
