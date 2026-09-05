<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Models\AddOn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // SubscriptionOverviewWidget (rendered on Analytics) reads AddOn::entry()
    // -- always present in a real install via AddOnSeeder, but this test
    // builds its own minimal fixture rather than seeding the whole app. See
    // AnalyticsPageTest for the same requirement.
    AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
});

// Covers both mechanisms: a hand-written page view with <x-screen-instructions/>
// inserted directly, and a resource/default-schema page reached only via the
// AdminPanelProvider::renderHook(CONTENT_START, ..., scopes: ...) loop. Each
// assertion also checks the heading appears exactly once, to catch the render
// hook accidentally firing twice (e.g. once globally, once scoped) on the same page.
test('resource and page screens each show their instructions panel exactly once', function (string $path, string $heading) {
    $admin = User::factory()->create(['active' => true, 'role' => Role::Admin]);

    $response = $this->actingAs($admin)->get($path)->assertSuccessful();

    expect(substr_count($response->getContent(), $heading))->toBe(1);
})->with([
    ['/admin', 'How to use the Dashboard'],
    ['/admin/analytics', 'How to use Analytics'],
    ['/admin/technical', 'How to use Technical'],
    ['/admin/members', 'How to use Members'],
    ['/admin/members/create', 'How to use Members'],
    ['/admin/vouchers', 'How to use Vouchers'],
    ['/admin/subscriptions', 'How to use Subscriptions'],
    ['/admin/feature-flags', 'How to use Feature Flags'],
    ['/admin/membership-settings', 'How to use Membership Settings'],
    ['/admin/role-labels', 'How to use Role Labels'],
]);

test('active patrons and check-in keep their existing instructions panel', function () {
    $volunteer = User::factory()->create(['active' => true, 'role' => Role::Volunteer]);
    $door = User::factory()->create(['active' => true, 'role' => Role::Door]);

    $this->actingAs($volunteer)->get('/admin/active-patrons')
        ->assertSuccessful()
        ->assertSee('How to use Active Patrons');

    $this->actingAs($door)->get('/admin/check-in')
        ->assertSuccessful()
        ->assertSee('How to check someone in');
});
