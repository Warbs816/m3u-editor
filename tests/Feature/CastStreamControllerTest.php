<?php

use Illuminate\Support\Facades\Http;

it('rejects cast segment requests without source', function () {
    $this->get(route('cast.stream.segment'))
        ->assertUnprocessable();
});

it('rejects cast segment requests for non proxy sources', function () {
    $this->get(route('cast.stream.segment', [
        'source' => 'https://example.com/video.ts',
    ]))
        ->assertUnprocessable();
});

it('rejects cast segment requests for cross-host m3u proxy sources', function () {
    $this->get(route('cast.stream.segment', [
        'source' => 'https://evil.test/m3u-proxy/hls/abc123/segment.ts?url=http%3A%2F%2Fupstream.test%2Fseg.ts',
    ]))
        ->assertUnprocessable();
});

it('proxies cast segment requests for same-host m3u proxy sources', function () {
    Http::fake([
        'https://m3u-editor.test/m3u-proxy/hls/abc123/segment.ts?url=http%3A%2F%2Fupstream.test%2Fseg.ts&client_id=client_1' => Http::response('segment-bytes', 200, [
            'Content-Type' => 'video/mp2t',
        ]),
    ]);

    $response = $this->get(route('cast.stream.segment', [
        'source' => 'https://m3u-editor.test/m3u-proxy/hls/abc123/segment.ts?url=http%3A%2F%2Fupstream.test%2Fseg.ts&client_id=client_1',
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'video/mp2t');
    expect($response->getContent())->toBe('segment-bytes');
});
