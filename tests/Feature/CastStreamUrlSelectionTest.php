<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;

it('includes a dedicated hls cast url for floating channel players', function () {
    $user = User::factory()->create(['name' => 'Harry']);
    $playlist = Playlist::factory()->for($user)->create(['uuid' => 'playlist-uuid']);

    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test/live/stream.ts',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/live/Harry/playlist-uuid/'.$channel->id.'.ts?proxy=true');
    expect($attributes['format'])->toBe('ts');
    expect($attributes['cast_url'])->toContain('/cast/live/Harry/playlist-uuid/'.$channel->id.'.m3u8');
});

it('returns no cast url when playlist context is missing', function () {
    $user = User::factory()->create(['name' => 'Harry']);

    $channel = Channel::factory()->for($user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => null,
        'url' => 'http://provider.test/live/stream.ts',
        'is_vod' => false,
    ]);

    $attributes = $channel->getFloatingPlayerAttributes();

    expect($attributes['cast_url'])->toBeNull();
    expect($attributes['cast_format'])->toBeNull();
});

it('includes a dedicated hls cast url for floating episode players', function () {
    $user = User::factory()->create(['name' => 'Harry']);
    $playlist = Playlist::factory()->for($user)->create(['uuid' => 'playlist-uuid']);

    $episode = Episode::factory()->for($user)->for($playlist)->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test/series/stream.ts',
    ]);

    $attributes = $episode->getFloatingPlayerAttributes();

    expect($attributes['url'])->toContain('/series/Harry/playlist-uuid/'.$episode->id.'.ts?proxy=true');
    expect($attributes['format'])->toBe('ts');
    expect($attributes['cast_url'])->toContain('/cast/series/Harry/playlist-uuid/'.$episode->id.'.m3u8');
    expect($attributes['cast_format'])->toBe('m3u8');
});
