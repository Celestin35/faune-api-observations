<?php

namespace Tests\Feature;

use App\Models\Observation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FauneFranceInboundTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authenticates_validates_and_persists(): void
    {
        $response = $this->withToken('test-faune-token')->postJson('/api/biodiversity/faune-france/occurrences', [
            'source_occurrence_id' => 'external-123',
            'scientific_name' => 'Tichodroma muraria',
            'observed_at' => '2026-01-11T09:43:39+01:00',
            'latitude' => 45.1,
            'longitude' => 6.2,
            'media' => [['url' => 'https://example.test/photo-page']],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('counts.created', 1);
        $this->assertDatabaseHas('observation_sources', ['source' => 'faune-france', 'source_occurrence_id' => 'external-123']);
    }

    #[Test]
    public function it_rejects_partial_coordinates(): void
    {
        $this->withToken('test-faune-token')->postJson('/api/biodiversity/faune-france/occurrences', [
            'source_occurrence_id' => 'external-123',
            'latitude' => 45.1,
        ])->assertUnprocessable()->assertJsonValidationErrors('longitude');
    }

    #[Test]
    public function it_rejects_an_invalid_token_and_imports_a_batch_idempotently(): void
    {
        $payload = [['source_occurrence_id' => 'batch-1', 'scientific_name' => 'Tichodroma muraria']];
        $this->postJson('/api/biodiversity/faune-france/occurrences', $payload)->assertUnauthorized();
        $this->withToken('test-faune-token')->postJson('/api/biodiversity/faune-france/occurrences', $payload)
            ->assertAccepted()->assertJsonPath('counts.created', 1);
        $this->withToken('test-faune-token')->postJson('/api/biodiversity/faune-france/occurrences', $payload)
            ->assertAccepted()->assertJsonPath('counts.unchanged', 1);
        self::assertSame(1, Observation::count());
    }
}
