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
    expect($attributes['cast_url'])->toContain('/live/Harry/playlist-uuid/'.$channel->id.'.m3u8?proxy=true');
    expect($attributes['cast_format'])->toBe('m3u8');
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
    expect($attributes['cast_url'])->toContain('/series/Harry/playlist-uuid/'.$episode->id.'.m3u8?proxy=true');
    expect($attributes['cast_format'])->toBe('m3u8');
});
