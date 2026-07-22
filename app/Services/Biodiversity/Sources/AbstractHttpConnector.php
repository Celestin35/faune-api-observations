<?php

namespace App\Services\Biodiversity\Sources;

use App\Services\Biodiversity\Exceptions\SourceRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractHttpConnector
{
    /** @var array<string, float> */
    private static array $lastRequestAt = [];

    /** @var array<string, string> */
    protected array $lastQuotaHeaders = [];

    abstract public function key(): string;

    abstract protected function baseUrl(): string;

    /**
     * @param  array<string, scalar|array<scalar>|null>  $query
     */
    protected function get(string $path, array $query): Response
    {
        $retriable = [429, 500, 502, 503, 504];
        $delaysMs = [200, 500, 1000];
        $attempt = 0;

        do {
            $this->throttle();
            $attempt++;

            try {
                $response = Http::baseUrl($this->baseUrl())
                    ->acceptJson()
                    ->withUserAgent((string) config('biodiversity.user_agent'))
                    ->timeout((int) config('biodiversity.timeout_seconds', 15))
                    ->get($path, array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== ''));
            } catch (ConnectionException $exception) {
                if ($attempt > count($delaysMs)) {
                    throw new SourceRequestException($this->key(), 0, "Connection to {$this->key()} failed: {$exception->getMessage()}");
                }

                $this->pause($delaysMs[$attempt - 1]);

                continue;
            }

            $this->lastQuotaHeaders = $this->extractQuotaHeaders($response);

            if (! in_array($response->status(), $retriable, true) || $attempt > count($delaysMs)) {
                break;
            }

            $this->pause($delaysMs[$attempt - 1]);
        } while (true);

        if (! $response->successful()) {
            throw new SourceRequestException(
                $this->key(),
                $response->status(),
                sprintf('%s returned HTTP %d: %s', $this->key(), $response->status(), mb_substr($response->body(), 0, 300)),
            );
        }

        if ($this->lastQuotaHeaders !== []) {
            Log::info('Biodiversity API quota headers', [
                'source' => $this->key(),
                'headers' => $this->lastQuotaHeaders,
            ]);
        }

        return $response;
    }

    /** @return array<string, string> */
    private function extractQuotaHeaders(Response $response): array
    {
        $captured = [];

        foreach ($response->headers() as $name => $values) {
            $lower = strtolower($name);
            if (str_contains($lower, 'rate') || str_contains($lower, 'quota') || $lower === 'retry-after') {
                $captured[$lower] = implode(', ', $values);
            }
        }

        return $captured;
    }

    private function throttle(): void
    {
        $minimumMs = (int) config('biodiversity.min_interval_ms', 500);
        $last = self::$lastRequestAt[$this->key()] ?? null;

        if ($minimumMs > 0 && $last !== null) {
            $remainingUs = (int) (($minimumMs / 1000 - (microtime(true) - $last)) * 1_000_000);
            if ($remainingUs > 0) {
                usleep($remainingUs);
            }
        }

        self::$lastRequestAt[$this->key()] = microtime(true);
    }

    private function pause(int $milliseconds): void
    {
        if ((int) config('biodiversity.retry_delay_multiplier', 1) > 0) {
            usleep($milliseconds * 1000 * (int) config('biodiversity.retry_delay_multiplier', 1));
        }
    }
}
