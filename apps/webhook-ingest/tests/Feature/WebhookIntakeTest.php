<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\RawWebhook;
use App\Infrastructure\Queue\PublishRawWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'mock';

    private const EVENT_ID = 'evt_001';

    private const PAYLOAD = '{"type":"payment.completed","id":"evt_001"}';

    private const SECRET = 'test-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('webhooks.providers.mock', [
            'signing_secret' => '',
            'signature_header' => 'x-webhook-signature',
            'event_id_header' => 'x-event-id',
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path — no signature enforcement
    // -----------------------------------------------------------------------

    public function test_returns_200_and_stores_raw_webhook(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID, 'CONTENT_TYPE' => 'application/json'],
            self::PAYLOAD,
        );

        $response->assertStatus(200)->assertJson(['status' => 'received']);

        $this->assertDatabaseHas('raw_webhooks', [
            'provider' => 'mock',
            'event_id' => self::EVENT_ID,
            'payload' => self::PAYLOAD,
        ]);

        Queue::assertPushed(PublishRawWebhookJob::class);
    }

    public function test_stores_raw_payload_before_queuing(): void
    {
        Queue::fake();

        $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID, 'CONTENT_TYPE' => 'application/json'],
            self::PAYLOAD,
        );

        $stored = RawWebhook::where('provider', 'mock')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($stored);

        Queue::assertPushed(PublishRawWebhookJob::class, function (PublishRawWebhookJob $job) use ($stored): bool {
            return $job->rawWebhookId === $stored->id;
        });
    }

    public function test_signature_not_verified_when_secret_is_empty(): void
    {
        Queue::fake();

        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID],
            self::PAYLOAD,
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('raw_webhooks', [
            'provider' => 'mock',
            'signature_verified' => false,
        ]);
    }

    public function test_propagates_correlation_id(): void
    {
        Queue::fake();
        $correlationId = '550e8400-e29b-41d4-a716-446655440000';

        $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID, 'HTTP_X-CORRELATION-ID' => $correlationId],
            self::PAYLOAD,
        );

        $this->assertDatabaseHas('raw_webhooks', [
            'provider' => 'mock',
            'correlation_id' => $correlationId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path — with signature verification
    // -----------------------------------------------------------------------

    public function test_accepts_valid_signature(): void
    {
        Queue::fake();

        Config::set('webhooks.providers.mock.signing_secret', self::SECRET);

        $signature = hash_hmac('sha256', self::PAYLOAD, self::SECRET);

        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            [
                'HTTP_X-EVENT-ID' => self::EVENT_ID,
                'HTTP_X-WEBHOOK-SIGNATURE' => $signature,
            ],
            self::PAYLOAD,
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('raw_webhooks', [
            'provider' => 'mock',
            'signature_verified' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Unknown provider
    // -----------------------------------------------------------------------

    public function test_returns_404_for_unknown_provider(): void
    {
        $response = $this->call(
            'POST',
            '/api/webhooks/unknown-provider',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID],
            self::PAYLOAD,
        );

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // Validation errors
    // -----------------------------------------------------------------------

    public function test_returns_422_for_empty_body(): void
    {
        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID],
            '',
        );

        $response->assertStatus(422)->assertJson(['message' => 'Payload must not be empty.']);
    }

    public function test_returns_422_when_event_id_header_is_missing(): void
    {
        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            [],
            self::PAYLOAD,
        );

        $response->assertStatus(422)->assertJson(['message' => 'Missing event identifier.']);
    }

    // -----------------------------------------------------------------------
    // Signature errors
    // -----------------------------------------------------------------------

    public function test_returns_401_when_signature_header_is_missing_but_secret_is_set(): void
    {
        Config::set('webhooks.providers.mock.signing_secret', self::SECRET);

        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            ['HTTP_X-EVENT-ID' => self::EVENT_ID],
            self::PAYLOAD,
        );

        $response->assertStatus(401)->assertJson(['message' => 'Unauthorized.']);
    }

    public function test_returns_401_for_invalid_signature(): void
    {
        Config::set('webhooks.providers.mock.signing_secret', self::SECRET);

        $response = $this->call(
            'POST',
            '/api/webhooks/mock',
            [],
            [],
            [],
            [
                'HTTP_X-EVENT-ID' => self::EVENT_ID,
                'HTTP_X-WEBHOOK-SIGNATURE' => 'badsignature',
            ],
            self::PAYLOAD,
        );

        $response->assertStatus(401)->assertJson(['message' => 'Unauthorized.']);
    }

    // -----------------------------------------------------------------------
    // Deduplication
    // -----------------------------------------------------------------------

    public function test_duplicate_event_returns_200_without_creating_duplicate_record(): void
    {
        Queue::fake();

        $serverVars = [
            'HTTP_X-EVENT-ID' => self::EVENT_ID,
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/webhooks/mock', [], [], [], $serverVars, self::PAYLOAD);
        $this->call('POST', '/api/webhooks/mock', [], [], [], $serverVars, self::PAYLOAD);

        $response = $this->call('POST', '/api/webhooks/mock', [], [], [], $serverVars, self::PAYLOAD);

        $response->assertStatus(200);

        $this->assertSame(1, RawWebhook::where('provider', 'mock')->where('event_id', self::EVENT_ID)->count());

        Queue::assertPushed(PublishRawWebhookJob::class, 1);
    }

    public function test_duplicate_event_logs_deduplication(): void
    {
        Queue::fake();
        Log::spy();

        $serverVars = [
            'HTTP_X-EVENT-ID' => self::EVENT_ID,
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->call('POST', '/api/webhooks/mock', [], [], [], $serverVars, self::PAYLOAD);
        $this->call('POST', '/api/webhooks/mock', [], [], [], $serverVars, self::PAYLOAD);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool =>
                $message === 'Duplicate webhook received — skipping'
                && $context['provider'] === 'mock'
                && $context['event_id'] === self::EVENT_ID,
            )
            ->once();
    }
}
