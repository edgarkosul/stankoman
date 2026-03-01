<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class CompanyLookupService
{
    private readonly string $base;

    private readonly ?string $token;

    private readonly ?string $secret;

    private readonly float $timeout;

    private readonly int $cacheTtl;

    public function __construct()
    {
        $config = config('services.dadata');

        $this->base = (string) ($config['base'] ?? 'https://suggestions.dadata.ru/suggestions/api/4_1/rs');
        $this->token = filled($config['token'] ?? null) ? (string) $config['token'] : null;
        $this->secret = filled($config['secret'] ?? null) ? (string) $config['secret'] : null;
        $this->timeout = (float) ($config['timeout'] ?? 4.0);
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? 86400);
    }

    /**
     * @return array{
     *     company_name?: string|null,
     *     inn?: string|null,
     *     kpp?: string|null,
     *     ogrn?: string|null,
     *     manager?: string|null,
     *     address?: string|null
     * }
     */
    public function byInn(string $inn): array
    {
        $innDigits = preg_replace('/\D+/', '', $inn) ?? '';

        if ($innDigits === '') {
            return [];
        }

        $cacheKey = "dadata:party:inn:{$innDigits}";
        $cached = Cache::get($cacheKey);

        if ($this->token === null) {
            return is_array($cached) ? $cached : [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token '.$this->token,
                'X-Secret' => (string) $this->secret,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post("{$this->base}/findById/party", ['query' => $innDigits]);

            if ($response->ok()) {
                $suggestion = data_get($response->json(), 'suggestions.0');

                if (is_array($suggestion)) {
                    $payload = $this->mapSuggestion($suggestion);
                    Cache::put($cacheKey, $payload, now()->addSeconds($this->cacheTtl));

                    return $payload;
                }
            }
        } catch (\Throwable) {
            // Ignore upstream failures and return cached payload if present.
        }

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array{
     *     company_name?: string|null,
     *     inn?: string|null,
     *     kpp?: string|null,
     *     ogrn?: string|null,
     *     manager?: string|null,
     *     address?: string|null
     * }
     */
    private function mapSuggestion(array $suggestion): array
    {
        $data = data_get($suggestion, 'data', []);

        if (! is_array($data)) {
            $data = [];
        }

        $companyName = data_get($data, 'name.full_with_opf')
            ?? data_get($data, 'name.short_with_opf')
            ?? data_get($data, 'name.full')
            ?? data_get($suggestion, 'value');

        $resolvedInn = data_get($data, 'inn');
        $innLength = is_string($resolvedInn) ? strlen($resolvedInn) : 0;
        $kpp = data_get($data, 'kpp');

        $manager = null;
        if (data_get($data, 'type') === 'LEGAL') {
            $manager = trim(implode(' ', array_filter([
                data_get($data, 'management.post'),
                data_get($data, 'management.name'),
            ], fn (mixed $part): bool => is_string($part) && $part !== '')));

            if ($manager === '') {
                $manager = null;
            }
        }

        return [
            'company_name' => is_string($companyName) ? $companyName : null,
            'inn' => is_string($resolvedInn) ? $resolvedInn : null,
            'kpp' => $innLength === 10 && is_string($kpp) ? $kpp : null,
            'ogrn' => data_get($data, 'ogrn'),
            'manager' => $manager,
            'address' => data_get($data, 'address.unrestricted_value'),
        ];
    }
}
