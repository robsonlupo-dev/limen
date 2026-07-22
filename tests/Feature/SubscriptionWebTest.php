<?php

use App\Models\Subscription;
use App\Models\TokenWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function subWebConsumer(): User
{
    return User::factory()->create(['role' => 'consumer', 'status' => 'active']);
}

function validCardPayload(string $slug = 'prestige'): array
{
    return [
        'circle_slug' => $slug,
        'card_holder' => 'Fulano de Tal',
        'card_number' => '5162 3062 1937 8829',
        'card_expiry_month' => 12,
        'card_expiry_year' => (int) date('Y') + 2,
        'card_cvv' => '123',
    ];
}

it('lists the five circles ordered by sort_order for a consumer', function () {
    $this->actingAs(subWebConsumer())
        ->get('/assinar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Subscription/Index')
            ->has('circles', 5)
            ->where('circles.0.slug', 'explorador')
            ->where('circles.4.slug', 'founders_circle')
            ->where('subscription', null)
        );
});

it('shows the current subscription when the user has one', function () {
    $user = subWebConsumer();
    Subscription::factory()->for($user)->circle('prestige')->create();

    $this->actingAs($user)
        ->get('/assinar')
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscription.circle', 'prestige')
            ->where('subscription.status', 'active')
        );
});

it('blocks the subscription pages for non-consumers and guests', function () {
    $this->get('/assinar')->assertRedirect(); // guest → login
    $performer = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $this->actingAs($performer)->get('/assinar')->assertForbidden();
    $this->actingAs($performer)->post('/assinar', validCardPayload())->assertForbidden();
});

it('subscribes with a valid card, grants tokens and redirects to the dashboard', function () {
    $user = subWebConsumer();

    $this->actingAs($user)
        ->post('/assinar', validCardPayload('prestige'))
        ->assertRedirect(route('consumer.dashboard'))
        ->assertSessionHas('success');

    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe('active')
        ->and($sub->card_last4)->toBe('8829');

    expect(TokenWallet::where('user_id', $user->id)->value('balance'))->toBe(500);
});

it('validates card fields and never flashes the card number or cvv', function () {
    $user = subWebConsumer();

    $response = $this->actingAs($user)->post('/assinar', [
        'circle_slug' => 'prestige',
        'card_holder' => '',
        'card_number' => '123', // too short
        'card_expiry_month' => 13, // invalid
        'card_expiry_year' => 2000, // past
        'card_cvv' => '12', // too short
    ]);

    $response->assertSessionHasErrors(['card_holder', 'card_number', 'card_expiry_month', 'card_expiry_year', 'card_cvv']);
    expect(Subscription::count())->toBe(0);

    // dontFlash: os campos sensíveis não voltam para a sessão.
    expect(session()->getOldInput('card_number'))->toBeNull()
        ->and(session()->getOldInput('card_cvv'))->toBeNull();
});

it('refuses to subscribe to an invite-only circle', function () {
    $user = subWebConsumer();

    $this->actingAs($user)
        ->post('/assinar', validCardPayload('founders_circle'))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Subscription::count())->toBe(0);
});

it('refuses a second subscription while one is active', function () {
    $user = subWebConsumer();
    Subscription::factory()->for($user)->circle('prestige')->create();

    $this->actingAs($user)
        ->post('/assinar', validCardPayload('insider'))
        ->assertSessionHas('error');

    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
});

it('cancels the active subscription at period end', function () {
    $user = subWebConsumer();
    $sub = Subscription::factory()->for($user)->circle('prestige')->create();

    $this->actingAs($user)
        ->post('/assinar/cancelar')
        ->assertRedirect(route('consumer.dashboard'))
        ->assertSessionHas('success');

    expect($sub->refresh()->cancel_at_period_end)->toBeTrue();
});

it('cancel with no active subscription returns an error', function () {
    $this->actingAs(subWebConsumer())
        ->post('/assinar/cancelar')
        ->assertSessionHas('error');
});

it('exposes the subscribe routes to Ziggy', function () {
    $only = config('ziggy.only');
    expect($only)->toContain('subscribe.index', 'subscribe.store', 'subscribe.cancel');
});
