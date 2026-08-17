<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AddressAutocompleteTest extends TestCase
{
    #[Test]
    public function it_returns_filtered_address_suggestions_from_the_official_geoplatform(): void
    {
        Http::fake([
            'data.geopf.fr/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'x' => -1.714408,
                    'y' => 48.123864,
                    'fulltext' => 'Square du Poitou, 35000 Rennes',
                    'kind' => 'street',
                    'city' => 'Rennes',
                    'zipcode' => '35000',
                    'ignored' => 'not exposed',
                ]],
            ]),
        ]);

        $this->getJson('/api/geocoding/addresses?q=Square%20du%20Poitou&limit=5')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'label' => 'Square du Poitou, 35000 Rennes',
                'latitude' => 48.123864,
                'longitude' => -1.714408,
                'kind' => 'street',
                'city' => 'Rennes',
                'postcode' => '35000',
            ]]]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://data.geopf.fr/geocodage/completion/?')
            && $request['text'] === 'Square du Poitou'
            && $request['type'] === 'StreetAddress,PositionOfInterest'
            && $request['maximumResponses'] === 5);
    }

    #[Test]
    public function it_validates_the_query_before_contacting_the_geocoding_service(): void
    {
        Http::fake();

        $this->getJson('/api/geocoding/addresses?q=ab')->assertUnprocessable()->assertJsonValidationErrors('q');

        Http::assertNothingSent();
    }
}
