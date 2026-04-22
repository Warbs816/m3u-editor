<?php

/**
 * Regression tests for the direct-stream proxy URL extension suffix.
 *
 * Clients like SIPTV classify VOD vs live purely by the URL suffix, so the
 * generated /stream/{id} URL must carry the right extension (`.mkv`, `.mp4`,
 * `.ts`, …) even when the underlying Xtream source URL is `.m3u8`. The
 * m3u-proxy strips any trailing known extension before looking up the
 * stream id, so the suffix is a client-side hint only.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_port' => null]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

/**
 * Build a fake HTTP response so findExistingPooledStream returns a pool match
 * for the given channel. The pool-reuse branch returns immediately through
 * buildProxyUrl, which is the behavior we want to assert on.
 */
function fakePooledStream(string $streamId, string $originalChannelId, string $playlistUuid): void
{
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => $streamId,
                    'client_count' => 1,
                    'metadata' => [
                        'original_channel_id' => $originalChannelId,
                        'original_playlist_uuid' => $playlistUuid,
                        'transcoding' => 'false',
                    ],
                ],
            ],
            'total_matching' => 1,
            'total_clients' => 1,
        ]),
    ]);
}

test('live channel proxy URL ends with the source .ts extension', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 2,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'url' => 'http://provider.com/live/user/pass/1234.ts',
    ]);

    fakePooledStream('pool-live-1', (string) $channel->id, $playlist->uuid);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->toEndWith('/stream/pool-live-1.ts');
});

test('vod channel with .mkv source keeps the .mkv extension', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 2,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
        'url' => 'http://provider.com/movie/user/pass/42.mkv',
    ]);

    fakePooledStream('pool-vod-mkv', (string) $channel->id, $playlist->uuid);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->toEndWith('/stream/pool-vod-mkv.mkv');
});

test('vod channel with .m3u8 source uses the container_extension instead of HLS path', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 2,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
        'url' => 'http://provider.com/movie/user/pass/42.m3u8',
    ]);

    fakePooledStream('pool-vod-m3u8', (string) $channel->id, $playlist->uuid);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->not->toContain('/hls/')
        ->and($url)->toEndWith('/stream/pool-vod-m3u8.mkv');
});

test('vod channel without container_extension falls back to .mp4', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 2,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => null,
        'url' => 'http://provider.com/movie/user/pass/42.m3u8',
    ]);

    fakePooledStream('pool-vod-default', (string) $channel->id, $playlist->uuid);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->toEndWith('/stream/pool-vod-default.mp4');
});

test('buildProxyUrl normalizes unknown formats to .ts and routes HLS to /hls/', function () {
    $service = app(M3uProxyService::class);
    $method = (new ReflectionClass($service))->getMethod('buildProxyUrl');
    $method->setAccessible(true);

    $build = fn (string $streamId, string $format) => $method->invoke($service, $streamId, $format, null);

    expect($build('abc', 'flv'))->toEndWith('/stream/abc.ts')
        ->and($build('abc', ''))->toEndWith('/stream/abc.ts')
        ->and($build('abc', '.MKV'))->toEndWith('/stream/abc.mkv')
        ->and($build('abc', 'mp4'))->toEndWith('/stream/abc.mp4')
        ->and($build('abc', 'ts'))->toEndWith('/stream/abc.ts')
        ->and($build('abc', 'hls'))->toContain('/hls/abc/playlist.m3u8')
        ->and($build('abc', 'm3u8'))->toContain('/hls/abc/playlist.m3u8');
});
