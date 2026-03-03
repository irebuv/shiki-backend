<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Google\Client;

class Ga4AnalyticsService {
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function overview(int $days = 14): array{
        $days = max(1, min($days, 90));
        $cacheSeconds = (int) env('GA4_CACHE_SECONDS', 300);

        return Cache::remember("ga4:overview:{$days}", $cacheSeconds, function () use ($days){
            $summaryReport = $this->runReport([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'engagementRate'],
                    ['name' => 'averageSessionDuration'],
                ],
                'metricAggregations' => ['TOTAL'],
            ]);

            $timeseriesReport = $this->runReport([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'date']],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'screenPageViews'],
                ],
                'orderBys' => [
                    ['dimension' => ['dimensionName' => 'date'], 'desc' => false],
                ],
                'limit' => (string) $days,
            ]);

            $pagesReport = $this->runReport([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [['name' => 'screenPageViews']],
                'orderBys' => [
                    ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
                ],
                'limit' => '10',
            ]);

            $sourceReport = $this->runReport([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'sessionSourceMedium']],
                'metrics' => [['name' => 'sessions']],
                'orderBys' => [
                    ['metric' => ['metricName' => 'sessions'], 'desc' => true],
                ],
                'limit' => '10',
            ]);

            $locationsReport = $this->runReport([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [
                    ['name' => 'country'],
                    ['name' => 'city'],
                ],
                'metrics' => [['name' => 'activeUsers']],
                'orderBys' => [
                    ['metric' => ['metricName' => 'activeUsers'], 'desc' => true],
                ],
                'limit' => '15',
            ]);

            // $countriesReport = $this->runReport([
            //     'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
            //     'dimensions' => [['name' => 'country']],
            //     'metrics' => [['name' => 'activeUsers']],
            //     'orderBys' => [
            //         ['metric' => ['metricName' => 'activeUsers'], 'desc' => true],
            //     ],
            //     'limit' => '10',
            // ]);

            return [
                'period_days' => $days,
                'summary' => $this->parseSummary($summaryReport),
                'timeseries' => $this->parseTimeseries($timeseriesReport),
                'top_pages' => $this->parseTopList($pagesReport, 'path', 'views'),
                'top_sources' => $this->parseTopList($sourceReport, 'source', 'sessions'),
                //'top_countries' => $this->parseTopList($locationsReport, 'country', 'users'),
                'top_locations' => $this->parseLocations($locationsReport),
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    public function realtime(): array{
        $cacheSeconds = (int) env('GA4_REALTIME_CACHE_SECONDS', 15);

        return Cache::remember('ga4:realtime', $cacheSeconds, function(){
            $summaryReport = $this->runRealtimeReport([
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'eventCount'],
                ],
                'metricAggregations' => ['TOTAL'],
            ]);

            $pagesReport = $this->runRealtimeReport([
                'dimensions' => [['name' => 'unifiedScreenName']],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'screenPageViews'],
                ],
                'orderBys' => [
                    ['metric' => ['metricName' => 'activeUsers'], 'desc' => true],
                ],
                'limit' => '10',
            ]);

            $locationsReport = $this->runRealtimeReport([
                'dimensions' => [
                    ['name' => 'country'],
                    ['name' => 'city'],
                ],
                'metrics' => [['name' => 'activeUsers']],
                'orderBys' => [
                    ['metric' => ['metricName' => 'activeUsers'], 'desc' => true],
                ],
                'limit' => '10',
            ]);

            return [
                'summary' => $this->parseRealtimeSummary($summaryReport),
                'top_pages' => $this->parseRealtimePages($pagesReport),
                'top_locations' => $this->parseLocations($locationsReport),
                'updated_at' => now()->toIso8601String(),
            ];
        });
    }

    private function runReport(array $payload): array{
        if(!filter_var(env('GA4_ENABLED', false), FILTER_VALIDATE_BOOL)){
            throw new RuntimeException('GA4 is disabled.');
        }

        $propertyId = trim((string) env('GA4_PROPERTY_ID', ''));
        if ($propertyId === ''){
            throw new RuntimeException('GA4_PROPERTY_ID is not configured.');
        }

        $token = $this->accessToken();
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";

        $response = Http::timeout(20)->withToken($token)->post($url, $payload);

        if ($response->failed()){
            throw new RuntimeException('GA4 request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    private function runRealtimeReport(array $payload): array{
        if(!filter_var(env('GA4_ENABLED', false), FILTER_VALIDATE_BOOL)){
            throw new RuntimeException('GA4 is disabled.');
        }

        $propertyId = trim((string) env('GA4_PROPERTY_ID', ''));
        if($propertyId === ''){
            throw new RuntimeException('GA4_PROPERTY_ID is not configured.');
        }

        $token = $this->accessToken();
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runRealtimeReport";

        $response = Http::timeout(20)->withToken($token)->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException('GA4 realtime request failed: ' .$response->body());
        }

        return $response->json() ?? [];
    }

    private function accessToken(): string{
        $credentialsPath = $this->credentialsPath();

        if (!is_file($credentialsPath)){
            throw new RuntimeException("GA4 credentials file not found: {$credentialsPath}");
        }

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([self::SCOPE]);

        $token = $client->fetchAccessTokenWithAssertion();

        if (!empty($token['error'])){
            $message = (string) ($token['error_description'] ?? $token['error']);
            throw new RuntimeException("GA4 token error: {$message}");
        }

        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === ''){
            throw new RuntimeException('GA4 token missing access_token.');
        }

        return $accessToken;
    }

    private function credentialsPath(): string
{
    $path = trim((string) env('GA4_CREDENTIALS_PATH', 'storage/app/private/ga4-service-account.json'));

    if ($path === '') {
        return base_path('storage/app/private/ga4-service-account.json');
    }

    // Unix absolute (/...) or Windows absolute (C:\... or C:/...)
    if (
        str_starts_with($path, '/') ||
        str_starts_with($path, '\\') ||
        preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
    ) {
        return $path;
    }

    return base_path($path);
}


    private function parseSummary(array $report): array{
        $totals = $report['totals'][0]['metricValues'] ?? [];

        return [
            'active_users' => (int) ($totals[0]['value'] ?? 0),
            'new_users' => (int) ($totals[1]['value'] ?? 0),
            'sessions' => (int) ($totals[2]['value'] ?? 0),
            'views' => (int) ($totals[3]['value'] ?? 0),
            'engagement_rate' => (float) ($totals[4]['value'] ?? 0),
            'avg_session_duration_sec' => (float) ($totals[5]['value'] ?? 0),
        ];
    }

    private function parseTimeseries(array $report): array{
        $rows = $report['rows'] ?? [];
        $result = [];

        foreach ($rows as $row) {
            $rawDate = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $result[] = [
                'date' => $this->formatGaDate($rawDate),
                'active_users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'views' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        return $result;
    }

    private function parseTopList(array $report, string $labelKey, string $valuesKey): array{
        $rows = $report['rows'] ?? [];
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                $labelKey => (string) ($row['dimensionValues'][0]['value'] ?? '(unknown)'),
                $valuesKey => (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }

        return $result;
    }

    private function parseLocations(array $report): array {
        $rows = $report['rows'] ?? [];
        $result = [];

        foreach ($rows as $row) {
            $country = (string) ($row['dimensionValues'][0]['value'] ?? 'Unknown country');
            $city = (string) ($row['dimensionValues'][1]['value'] ?? 'Unknown city');
            $users = (int) ($row['metricValues'][0]['value'] ?? 0);

            $result[] = [
                'location' => "{$country}, {$city}",
                'users' => $users,
            ];
        }

        return $result;
    }

    private function parseRealtimeSummary(array $report): array{
        $totals = $report['totals'][0]['metricValues'] ?? [];

        return [
            'active_users_last_30_min' => (int) ($totals[0]['value'] ?? 0),
            'views_last_30_min' => (int) ($totals[1]['value'] ?? 0),
            'events_last_30_min' => (int) ($totals[2]['value'] ?? 0),
        ];
    }

    private function parseRealtimePages(array $report): array{
        $rows = $report['rows'] ?? [];
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'path' => (string) ($row['dimensionValues'][0]['value'] ?? '(unknown)'),
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'views' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        return $result;
    }

    private function formatGaDate(string $raw): string{
        if (preg_match('/^\d{8}$/', $raw) !== 1){
            return $raw;
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
    }

}
