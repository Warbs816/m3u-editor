<?php

use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Helper: get the epg_channel_id from the first mapped record in job output,
 * then verify it points to the expected EpgChannel by channel_id string.
 */
function assertMappedToEpgChannel(string $expectedChannelId): void
{
    $jobRecords = Job::where('batch_no', '!=', '')->get();
    expect($jobRecords)->not->toBeEmpty();

    $payload = $jobRecords->first()->payload;
    $matched = EpgChannel::find($payload[0]['epg_channel_id']);
    expect($matched)->not->toBeNull();
    expect($matched->channel_id)->toBe($expectedChannelId);
}

beforeEach(function () {
    Event::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->epg = Epg::factory()->create(['user_id' => $this->user->id]);
    $this->epgMap = EpgMap::create([
        'name' => 'Test Map',
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'uuid' => fake()->uuid(),
        'status' => 'processing',
        'processing' => true,
    ]);
});

it('extracts capture group in regex extract mode and appends suffix', function () {
    // Create an EPG channel that should match after extraction + suffix
    $epgChannel = EpgChannel::create([
        'epg_id' => $this->epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'OHIU-DT',
        'name' => 'OHIU-DT',
        'display_name' => 'OHIU-DT',
    ]);

    // Create a channel with a complex name containing a 4-letter station ID
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'title' => 'CBS 123 (OHIU) Local',
        'name' => 'CBS 123 (OHIU) Local',
        'stream_id' => 'OHIU',
        'is_vod' => false,
        'epg_channel_id' => null,
        'epg_map_enabled' => true,
    ]);

    $job = new MapPlaylistChannelsToEpgChunk(
        channelIds: [$channel->id],
        epgId: $this->epg->id,
        epgMapId: $this->epgMap->id,
        settings: [
            'use_regex' => true,
            'regex_extract_mode' => true,
            'exclude_prefixes' => ['(?<![A-Z])([A-Z]{4})(?![A-Z])'],
            'append_suffix' => '-DT',
        ],
        batchNo: fake()->uuid(),
        totalChannels: 1,
    );

    $job->handle();

    assertMappedToEpgChannel('OHIU-DT');
});

it('removes matched text in default regex mode (not extract)', function () {
    // EPG channel named just "Local" — should match after prefix removal
    $epgChannel = EpgChannel::create([
        'epg_id' => $this->epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'local',
        'name' => 'Local',
        'display_name' => 'Local',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'title' => 'US: Local',
        'name' => 'US: Local',
        'stream_id' => 'us-local',
        'is_vod' => false,
        'epg_channel_id' => null,
        'epg_map_enabled' => true,
    ]);

    $job = new MapPlaylistChannelsToEpgChunk(
        channelIds: [$channel->id],
        epgId: $this->epg->id,
        epgMapId: $this->epgMap->id,
        settings: [
            'use_regex' => true,
            'regex_extract_mode' => false,
            'exclude_prefixes' => ['^US:\s*'],
            'append_suffix' => '',
        ],
        batchNo: fake()->uuid(),
        totalChannels: 1,
    );

    $job->handle();

    assertMappedToEpgChannel('local');
});

it('appends suffix without regex when only suffix is configured', function () {
    $epgChannel = EpgChannel::create([
        'epg_id' => $this->epg->id,
        'user_id' => $this->user->id,
        'channel_id' => 'espn-hd',
        'name' => 'ESPN-HD',
        'display_name' => 'ESPN-HD',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'title' => 'ESPN',
        'name' => 'ESPN',
        'stream_id' => 'ESPN',
        'is_vod' => false,
        'epg_channel_id' => null,
        'epg_map_enabled' => true,
    ]);

    $job = new MapPlaylistChannelsToEpgChunk(
        channelIds: [$channel->id],
        epgId: $this->epg->id,
        epgMapId: $this->epgMap->id,
        settings: [
            'use_regex' => false,
            'exclude_prefixes' => [],
            'append_suffix' => '-HD',
        ],
        batchNo: fake()->uuid(),
        totalChannels: 1,
    );

    $job->handle();

    assertMappedToEpgChannel('espn-hd');
});
