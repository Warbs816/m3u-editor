<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class CastStreamController extends Controller
{
    public function live(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'live', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function movie(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'movie', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function series(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'series', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function segment(Request $request): Response
    {
        $source = $request->query('source');

        if (! is_string($source) || $source === '') {
            return response('Missing source', 422);
        }

        $parsedSource = parse_url($source);
        $path = $parsedSource['path'] ?? null;
        $query = isset($parsedSource['query']) ? '?'.$parsedSource['query'] : '';
        $sourceHost = $parsedSource['host'] ?? null;
        $requestHost = $request->getHost();

        if (! is_string($path) || ! str_starts_with($path, '/m3u-proxy/')) {
            return response('Invalid source', 422);
        }

        if (is_string($sourceHost) && strcasecmp($sourceHost, $requestHost) !== 0) {
            return response('Invalid source host', 422);
        }

        $upstreamResponse = Http::timeout(30)
            ->withHeaders($this->forwardHeaders($request))
            ->get(url($path.$query));

        return response($upstreamResponse->body(), $upstreamResponse->status(), [
            'Content-Type' => $upstreamResponse->header('Content-Type', 'video/mp2t'),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    protected function playlist(Request $request, string $type, string $username, string $password, string|int $streamId, string $format): Response
    {
        $bootstrapRequest = Request::create(
            uri: match ($type) {
                'live' => "/live/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
                'movie' => "/movie/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
                'series' => "/series/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
            },
            method: 'GET',
            cookies: $request->cookies->all(),
            server: [
                'HTTP_HOST' => $request->getHost(),
                'HTTPS' => $request->isSecure() ? 'on' : 'off',
                'REMOTE_ADDR' => $request->ip(),
            ],
        );

        $bootstrapResponse = app()->handle($bootstrapRequest);

        if (! $bootstrapResponse instanceof RedirectResponse) {
            return response('Stream bootstrap failed', 422);
        }

        $resolvedUrl = $bootstrapResponse->getTargetUrl();
        $playlistResponse = Http::timeout(30)
            ->withHeaders($this->forwardHeaders($request))
            ->get($resolvedUrl);

        if (! $playlistResponse->successful()) {
            return response($playlistResponse->body(), $playlistResponse->status(), [
                'Content-Type' => $playlistResponse->header('Content-Type', 'text/plain'),
            ]);
        }

        $playlist = $playlistResponse->body();
        $resolvedParts = parse_url($resolvedUrl);
        $resolvedPath = $resolvedParts['path'] ?? '';
        $resolvedDir = rtrim((string) preg_replace('#/[^/]+$#', '', $resolvedPath), '/');

        $playlist = preg_replace_callback('/^(?!#)(.+)$/m', function (array $matches) use ($resolvedDir) {
            $line = trim($matches[1]);

            if ($line === '') {
                return $matches[0];
            }

            if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
                $segmentUrl = $line;
            } elseif (str_starts_with($line, '/')) {
                $segmentUrl = url($line);
            } else {
                $segmentUrl = url($resolvedDir.'/'.$line);
            }

            return route('cast.stream.segment', ['source' => $segmentUrl]);
        }, $playlist) ?? $playlist;

        return response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    protected function forwardHeaders(Request $request): array
    {
        $headers = [];
        $range = $request->header('Range');

        if (is_string($range) && $range !== '') {
            $headers['Range'] = $range;
        }

        return $headers;
    }
}
