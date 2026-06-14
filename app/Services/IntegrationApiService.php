<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IntegrationApiService
{
    public function getToken(): string
    {
        $baseUrl = config('integration.base_url');
        $username = config('integration.username');
        $password = config('integration.password');

        if ($baseUrl === '' || empty($username) || empty($password)) {
            throw new RuntimeException(
                'Integration API is not configured. Set INTEGRATION_API_* variables in .env.'
            );
        }

        $response = Http::timeout(config('integration.timeout_token'))
            ->get($baseUrl.config('integration.token_path'), [
                'username' => $username,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            Log::error('Integration API token retrieval failed', ['status' => $response->status()]);

            throw new RuntimeException(
                'Failed to retrieve authentication token (HTTP '.$response->status().').'
            );
        }

        $token = $this->extractToken($response->json(), $response->body());

        if (empty($token)) {
            Log::error('Integration API token not found in response');

            throw new RuntimeException('Token not found in API response. Check application logs.');
        }

        return $token;
    }

    /**
     * Fetch logs for a single employee (kept for individual-query use cases).
     *
     * @return array{0: array<int, mixed>, 1: int}
     */
    public function fetchLogsForPersonnel(
        string $token,
        string $empNo,
        string $from,
        string $to
    ): array {
        return $this->postLogs($token, $empNo, $from, $to, 0, (int) config('integration.logs_page_size', 1000));
    }

    /**
     * Fetch one page of logs for ALL employees (PersonnelNo='').
     * Repeat with increasing $start until the returned array is smaller than $max.
     *
     * @return array{0: array<int, mixed>, 1: int}
     */
    public function fetchBulkLogs(
        string $token,
        string $from,
        string $to,
        int $start = 0,
        int $max = 1000
    ): array {
        return $this->postLogs($token, '', $from, $to, $start, $max);
    }

    /**
     * @return array{0: array<int, mixed>, 1: int}
     */
    private function postLogs(
        string $token,
        string $personnelNo,
        string $from,
        string $to,
        int $start,
        int $max
    ): array {
        $response = Http::timeout(config('integration.timeout_logs'))
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post(config('integration.base_url').config('integration.logs_path'), [
                'PersonnelNo' => $personnelNo,
                'StartDate' => $from,
                'EndDate' => $to,
                'start' => $start,
                'max' => $max,
            ]);

        if (! $response->successful()) {
            Log::warning('Integration API log fetch failed', [
                'personnel_no' => $personnelNo ?: '(all)',
                'start' => $start,
                'status' => $response->status(),
            ]);

            return [[], $response->status()];
        }

        $payload = $response->json();
        $logsData = $payload['data'] ?? (is_array($payload) ? $payload : []);

        return [is_array($logsData) ? $logsData : [], $response->status()];
    }

    public function extractToken(mixed $data, string $rawBody): ?string
    {
        $find = function (mixed $d) use (&$find): ?string {
            if (is_null($d)) {
                return null;
            }

            if (is_string($d)) {
                return $d;
            }

            if (is_array($d)) {
                foreach (['token', 'Token', 'access_token', 'AccessToken', 'accessToken', 'data', 'Data', 'result', 'Result'] as $k) {
                    if (array_key_exists($k, $d)) {
                        $val = $d[$k];
                        if (is_string($val) && strlen(trim($val)) > 0) {
                            return $val;
                        }
                        $found = $find($val);
                        if (! empty($found)) {
                            return $found;
                        }
                    }
                }

                foreach ($d as $val) {
                    $found = $find($val);
                    if (! empty($found)) {
                        return $found;
                    }
                }
            }

            return null;
        };

        $token = $find($data);

        if (empty($token)) {
            $raw = trim($rawBody);
            if (strlen($raw) >= 20) {
                $token = $raw;
            }
        }

        return $token ?: null;
    }
}
