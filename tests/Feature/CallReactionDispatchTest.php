<?php

use App\Events\CallReactionReceived;
use App\Models\Gift;
use App\Models\User;
use App\Services\GiftService;
use App\Services\TipService;
use App\Services\TokenService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * A reação de gorjeta/presente na chamada 1:1 chega à performer pelo servidor
 * (CallReactionReceived no canal pessoal user.{id}) — trilho confiável, não o
 * pacote cliente-a-cliente do LiveKit. Aqui garantimos que Tip/GiftService
 * disparam o evento com o id da performer e payload não-sensível.
 */
function icrbPerformer(): array
{
    $user = User::factory()->create(['role' => 'performer', 'status' => 'active']);
    $profile = $user->performerProfile()->create([
        'stage_name' => 'Perf '.Str::random(6),
        'slug' => 'perf-'.strtolower(Str::random(6)),
        'category' => 'mulheres',
        'is_verified' => true,
        'level' => 'iniciante',
        'split_pct' => 70,
    ]);

    return [$user, $profile];
}

function icrbConsumer(int $balance): User
{
    $user = User::factory()->create(['role' => 'consumer', 'status' => 'active']);
    if ($balance > 0) {
        app(TokenService::class)->credit($user, $balance, 'purchase');
    }

    return $user;
}

it('dispara CallReactionReceived para a performer ao receber gorjeta', function () {
    Event::fake([CallReactionReceived::class]);

    [$performerUser, $profile] = icrbPerformer();
    $consumer = icrbConsumer(100);

    app(TipService::class)->send($consumer, $profile, 30, (string) Str::uuid());

    Event::assertDispatched(CallReactionReceived::class, function ($e) use ($performerUser) {
        return $e->performerUserId === $performerUser->id
            && $e->payload['type'] === 'tip'
            && $e->payload['amount'] === 30;
    });
});

it('dispara CallReactionReceived para a performer ao receber presente', function () {
    Event::fake([CallReactionReceived::class]);

    [$performerUser, $profile] = icrbPerformer();
    $consumer = icrbConsumer(100);
    $gift = Gift::create(['name' => 'Rosa', 'slug' => 'rosa', 'price_tokens' => 8, 'active' => true]);

    app(GiftService::class)->send($consumer, $profile, $gift, (string) Str::uuid());

    Event::assertDispatched(CallReactionReceived::class, function ($e) use ($performerUser) {
        return $e->performerUserId === $performerUser->id
            && $e->payload['type'] === 'gift'
            && $e->payload['gift_slug'] === 'rosa'
            && $e->payload['gift_name'] === 'Rosa';
    });
});

it('não redispara no retorno idempotente da gorjeta', function () {
    Event::fake([CallReactionReceived::class]);

    [, $profile] = icrbPerformer();
    $consumer = icrbConsumer(100);
    $key = (string) Str::uuid();

    app(TipService::class)->send($consumer, $profile, 15, $key);
    app(TipService::class)->send($consumer, $profile, 15, $key); // retry — mesma chave

    Event::assertDispatchedTimes(CallReactionReceived::class, 1);
});

it('o evento transmite no canal pessoal da performer', function () {
    [$performerUser] = icrbPerformer();
    $event = new CallReactionReceived($performerUser->id, ['type' => 'tip', 'amount' => 5]);

    $channels = $event->broadcastOn();
    expect($channels[0]->name)->toContain('user.'.$performerUser->id);
    expect($event->broadcastAs())->toBe('call.reaction');
    // Payload não-sensível: nunca member_id/saldo/tier.
    expect($event->broadcastWith())->toBe(['type' => 'tip', 'amount' => 5]);
});
