<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Push\Contracts\PushGateway;
use App\Push\NullPushGateway;
use App\Push\PushMessage;
use App\Push\Vapid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Telling the other phone.
 *
 * The rules worth pinning are all consequences of one household being one
 * account: there is nobody to address a notification to, so "the other phone"
 * has to mean "every device of this account except the one that caused it", and
 * a subscription is a property of a device rather than of a person.
 *
 * The gateway is faked throughout. The real one posts to Google and Apple, so
 * without a seam every one of these would either hit the network or prove
 * nothing at all.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RecordingPushGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->gateway = new RecordingPushGateway;
        $this->app->instance(PushGateway::class, $this->gateway);
    }

    public function test_a_phone_can_subscribe_itself(): void
    {
        $this->actingAs($this->user)
            ->post(route('push.subscribe'), $this->subscription())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $subscription = PushSubscription::query()->firstOrFail();

        $this->assertSame($this->user->id, $subscription->user_id);
        $this->assertSame('phone-a', $subscription->device_id);
    }

    /**
     * A browser may hand the same phone the same endpoint again — on every visit
     * to the screen, in fact, because the client reuses an existing subscription
     * rather than minting a new one. A second row would mean a second delivery
     * for one notification, which is one buzz too many.
     */
    public function test_subscribing_the_same_endpoint_twice_keeps_one_row(): void
    {
        $this->actingAs($this->user)->post(route('push.subscribe'), $this->subscription());
        $this->actingAs($this->user)->post(route('push.subscribe'), $this->subscription(auth: 'nowszy'));

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame('nowszy', PushSubscription::query()->firstOrFail()->auth_token);
    }

    /**
     * The rule this whole feature turns on. Both phones are signed in as the
     * same account, so notifying "the user" would buzz the pocket of the person
     * still holding the phone they typed it into.
     */
    public function test_writing_a_task_tells_the_other_phone_and_not_the_one_that_wrote_it(): void
    {
        $this->subscribe('phone-a', 'https://push.example/a');
        $this->subscribe('phone-b', 'https://push.example/b');

        $this->actingAs($this->user)
            ->post(route('tasks.store'), [
                'title' => 'Podjechać po chleb',
                'assignee' => 'him',
                'device' => 'phone-a',
            ])
            ->assertRedirect();

        $this->assertSame(
            [['https://push.example/b']],
            $this->gateway->endpoints(),
        );
    }

    /**
     * A phone that never turned notifications on has no id to send. "Tell
     * everybody" is the right reading of that, exactly as an empty shop
     * selection means every shop rather than none.
     */
    public function test_a_request_that_cannot_name_its_device_tells_every_phone(): void
    {
        $this->subscribe('phone-a', 'https://push.example/a');
        $this->subscribe('phone-b', 'https://push.example/b');

        $this->actingAs($this->user)
            ->post(route('tasks.store'), ['title' => 'Oddać buty', 'assignee' => 'both']);

        $this->assertSame(
            [['https://push.example/a', 'https://push.example/b']],
            $this->gateway->endpoints(),
        );
    }

    public function test_the_notification_names_the_task_and_the_day_it_is_due(): void
    {
        $this->subscribe('phone-b', 'https://push.example/b');

        $this->actingAs($this->user)
            ->post(route('tasks.store'), [
                'title' => 'Podjechać po chleb',
                'assignee' => 'him',
                'due_on' => now()->toDateString(),
                'device' => 'phone-a',
            ]);

        $message = $this->gateway->sent[0];

        $this->assertSame('Nowe zadanie', $message->title);
        $this->assertSame('Podjechać po chleb — dziś', $message->body);
        $this->assertSame('/zadania', $message->url);
        // The badge is the open count, so a swiped-away banner still leaves the
        // number on the home-screen icon.
        $this->assertSame(1, $message->badge);
    }

    /**
     * A phone that deleted the app answers 404/410 for good. Keeping the row
     * would have every later task knock on a dead endpoint, and would list a
     * revoked phone among those that can be told.
     */
    public function test_a_subscription_the_service_says_is_gone_is_deleted(): void
    {
        $this->subscribe('phone-b', 'https://push.example/b');
        $this->gateway->gone = ['https://push.example/b'];

        $this->actingAs($this->user)
            ->post(route('tasks.store'), ['title' => 'Oddać buty', 'assignee' => 'both']);

        $this->assertSame(0, PushSubscription::query()->count());
    }

    /**
     * A push service having a bad afternoon is not a reason for a subscription
     * to be lost. Only "gone for good" is acted on; everything else is left
     * alone and tried again next time.
     */
    public function test_a_failed_delivery_does_not_cost_a_phone_its_subscription(): void
    {
        $this->subscribe('phone-b', 'https://push.example/b');

        $this->actingAs($this->user)
            ->post(route('tasks.store'), ['title' => 'Oddać buty', 'assignee' => 'both']);

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_a_phone_can_unsubscribe_itself(): void
    {
        $this->subscribe('phone-a', 'https://push.example/a');

        $this->actingAs($this->user)
            ->delete(route('push.unsubscribe'), ['endpoint' => 'https://push.example/a'])
            ->assertRedirect();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    /**
     * The endpoint is unique across the table, which is not a licence to delete
     * a row by guessing one. Same rule as every other household-scoped write.
     */
    public function test_another_household_cannot_unsubscribe_this_ones_phone(): void
    {
        $this->subscribe('phone-a', 'https://push.example/a');

        $this->actingAs(User::factory()->create())
            ->delete(route('push.unsubscribe'), ['endpoint' => 'https://push.example/a']);

        $this->assertSame(1, PushSubscription::query()->count());
    }

    /**
     * An installation nobody has run `push:keys` on does not do notifications,
     * and has to read as "quietly nothing" rather than as an error — including
     * on the screen, which offers no switch it could only fail to honour.
     */
    public function test_an_installation_without_keys_sends_nothing_and_offers_nothing(): void
    {
        config()->set('push.vapid.public_key', null);
        config()->set('push.vapid.private_key', null);

        $this->app->forgetInstance(Vapid::class);
        $this->app->forgetInstance(PushGateway::class);

        $this->assertInstanceOf(NullPushGateway::class, $this->app->make(PushGateway::class));

        $this->actingAs($this->user)
            ->get(route('tasks.index'))
            ->assertInertia(fn ($page) => $page->where('pushKey', null));
    }

    public function test_the_key_reaches_the_screen_when_there_is_one(): void
    {
        config()->set('push.vapid.public_key', 'a-public-key');
        config()->set('push.vapid.private_key', 'a-private-key');

        $this->app->forgetInstance(Vapid::class);

        $this->actingAs($this->user)
            ->get(route('tasks.index'))
            ->assertInertia(fn ($page) => $page->where('pushKey', 'a-public-key'));
    }

    private function subscribe(string $device, string $endpoint): void
    {
        PushSubscription::query()->create([
            'user_id' => $this->user->id,
            'endpoint' => $endpoint,
            'public_key' => 'p256dh',
            'auth_token' => 'auth',
            'device_id' => $device,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function subscription(string $auth = 'auth'): array
    {
        return [
            'endpoint' => 'https://push.example/a',
            'public_key' => 'p256dh',
            'auth_token' => $auth,
            'device' => 'phone-a',
            'label' => 'iPhone',
        ];
    }
}

/**
 * Stands in for Google and Apple: remembers what it was asked to deliver, and
 * reports back whatever the test wants it to about who is gone.
 */
final class RecordingPushGateway implements PushGateway
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /** @var list<list<string>> */
    public array $to = [];

    /** @var list<string> */
    public array $gone = [];

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<string>
     */
    public function deliver(PushMessage $message, Collection $subscriptions): array
    {
        $this->sent[] = $message;
        $this->to[] = $subscriptions->pluck('endpoint')->values()->all();

        return $this->gone;
    }

    /**
     * @return list<list<string>>
     */
    public function endpoints(): array
    {
        return $this->to;
    }
}
