<?php

use App\Enums\Status;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Jobs\ImportPlaylist;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\DynamicGroup;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\SourceGroup;
use App\Models\User;
use App\Services\PlaylistTransferService;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(PlaylistTransferService::class);
});

/**
 * Build a playlist with groups, channels, a failover, series and an EPG mapping.
 */
function makeExportablePlaylist(User $user): Playlist
{
    $playlist = Playlist::factory()->create([
        'user_id' => $user->id,
        'name' => 'Source Playlist',
        'url' => 'http://provider.test/get.php',
        'status' => Status::Completed,
        'xtream_config' => ['url' => 'http://provider.test', 'username' => 'u', 'password' => 'p'],
        'import_prefs' => ['selected_groups' => ['News']],
        'auto_sort' => true,
    ]);

    SourceGroup::query()->create(['playlist_id' => $playlist->id, 'name' => 'News', 'type' => 'live']);

    $epg = Epg::factory()->create(['user_id' => $user->id, 'name' => 'Guide', 'url' => 'http://epg.test/guide.xml']);
    $epgChannel = EpgChannel::factory()->create([
        'user_id' => $user->id,
        'epg_id' => $epg->id,
        'channel_id' => 'bbc.uk',
        'name' => 'BBC One',
    ]);

    $mergedGroup = Group::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'name' => 'All News',
        'name_internal' => 'All News',
        'type' => 'live',
        'is_merged' => true,
    ]);
    $group = Group::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'name' => 'News',
        'name_internal' => 'News',
        'type' => 'live',
        'parent_id' => $mergedGroup->id,
    ]);

    $channelDefaults = [
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'group' => 'News',
        'group_internal' => 'News',
        'logo' => null,
        'enabled' => false,
    ];

    $primary = Channel::factory()->create([
        'name' => 'BBC',
        'title' => 'BBC',
        'source_id' => 'src-bbc',
        'name_custom' => 'BBC One HD',
        'channel' => 101,
        'enabled' => true,
        'epg_channel_id' => $epgChannel->id,
        'extvlcopt' => [['key' => 'http-user-agent', 'value' => 'VLC']],
    ] + $channelDefaults);
    $backup = Channel::factory()->create([
        'name' => 'BBC Backup',
        'title' => 'BBC Backup',
        'source_id' => 'src-bbc-backup',
    ] + $channelDefaults);
    ChannelFailover::query()->create([
        'user_id' => $user->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $backup->id,
        'sort' => 1,
    ]);

    // Disabled, untouched by the user: the next sync recreates it, so it is not exported
    Channel::factory()->create([
        'name' => 'Untouched',
        'title' => 'Untouched',
        'source_id' => 'src-untouched',
    ] + $channelDefaults);

    Channel::factory()->create([
        'name' => 'AIO clone',
        'title' => 'AIO clone',
        'is_aio_failover_clone' => true,
    ] + $channelDefaults);

    Bouquet::query()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'name' => 'Favourites',
        'group_selections' => ['live' => ['News']],
        'auto_include_new_live' => true,
    ]);
    DynamicGroup::query()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Trending',
        'tmdb_params' => ['list' => 'trending'],
        'enabled' => true,
    ]);

    $category = Category::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'name' => 'Drama']);
    $series = Series::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'category_id' => $category->id, 'name' => 'Show', 'enabled' => true]);
    $season = Season::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'category_id' => $category->id, 'series_id' => $series->id, 'season_number' => 1]);
    Episode::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'series_id' => $series->id, 'season_id' => $season->id, 'title' => 'Pilot']);

    // Disabled series are re-listed by the next sync and their episodes are only fetched once enabled
    $disabledSeries = Series::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'category_id' => $category->id, 'name' => 'Ignored Show', 'enabled' => false]);
    $disabledSeason = Season::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'category_id' => $category->id, 'series_id' => $disabledSeries->id]);
    Episode::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'series_id' => $disabledSeries->id, 'season_id' => $disabledSeason->id, 'title' => 'Ignored']);

    return $playlist;
}

/**
 * Write the gzipped export of a playlist to a temp file and return its path.
 */
function exportToTempFile(PlaylistTransferService $service, Playlist $playlist): string
{
    $path = tempnam(sys_get_temp_dir(), 'playlist-export-').'.jsonl.gz';
    $handle = fopen($path, 'wb');
    $service->writeExport($playlist, fn (string $bytes) => fwrite($handle, $bytes));
    fclose($handle);

    return $path;
}

/**
 * Decode an export into the header plus records grouped by type.
 *
 * @return array<string, mixed>
 */
function decodeExport(string $gzipped): array
{
    $lines = array_values(array_filter(explode("\n", gzdecode($gzipped)), fn (string $line): bool => $line !== ''));
    $decoded = ['header' => json_decode(array_shift($lines), true)];
    foreach ($lines as $line) {
        $record = json_decode($line, true);
        $decoded[$record['type']][] = $record['data'];
    }

    return $decoded;
}

it('exports a playlist without any instance specific ids', function () {
    $playlist = makeExportablePlaylist($this->user);

    $export = decodeExport(file_get_contents(exportToTempFile($this->service, $playlist)));

    expect($export['header']['format'])->toBe(PlaylistTransferService::FORMAT)
        ->and($export['header']['version'])->toBe(PlaylistTransferService::VERSION)
        ->and($export['playlist'])->toHaveCount(1)
        ->and($export['playlist'][0])->not->toHaveKeys(['id', 'uuid', 'user_id', 'status', 'uploads', 'short_urls'])
        ->and($export['playlist'][0]['name'])->toBe('Source Playlist')
        ->and($export['playlist'][0]['xtream_config']['username'])->toBe('u')
        ->and($export['epg'])->toBe([['key' => $export['epg'][0]['key'], 'name' => 'Guide', 'url' => 'http://epg.test/guide.xml']])
        ->and($export['source_group'][0]['name'])->toBe('News')
        ->and($export['bouquet'])->toHaveCount(1)
        ->and($export['bouquet'][0]['name'])->toBe('Favourites')
        ->and($export['bouquet'][0])->not->toHaveKeys(['id', 'user_id', 'playlist_id'])
        ->and($export['dynamic_group'][0]['name'])->toBe('Trending')
        ->and($export['group'])->toHaveCount(2)
        ->and($export['group'][0]['is_merged'])->toBeTrue()
        ->and($export['group'][0]['parent_key'])->toBeNull()
        ->and($export['group'][1]['parent_key'])->toBe($export['group'][0]['key'])
        ->and($export['group'][1])->not->toHaveKeys(['parent_id', 'aed_profile_id'])
        ->and($export['category'])->toHaveCount(1)
        ->and($export['series'])->toHaveCount(1)
        ->and($export['series'][0]['name'])->toBe('Show')
        ->and($export['season'])->toHaveCount(1)
        ->and($export['episode'])->toHaveCount(1)
        ->and($export['episode'][0]['title'])->toBe('Pilot');

    $channels = collect($export['channel']);
    expect($channels->pluck('name')->all())->toBe(['BBC', 'BBC Backup'])
        ->and($channels[0])->not->toHaveKeys(['id', 'uuid', 'user_id', 'playlist_id', 'group_id', 'epg_channel_id', 'dvr_recording_id', 'aio_integration_id', 'last_scrubbed_at'])
        ->and($channels[0]['group_key'])->toBe($export['group'][1]['key'])
        ->and($channels[0]['epg'])->toBe([
            'epg_key' => $export['epg'][0]['key'],
            'channel_id' => 'bbc.uk',
            'name' => 'BBC One',
        ])
        ->and($channels[0]['failovers'][0]['channel_key'])->toBe($channels[1]['key'])
        ->and($channels[1]['epg'])->toBeNull();
});

it('keeps a disabled channel only when something about it would not survive a sync', function (array $attributes) {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id, 'name' => 'Rules']);
    $group = Group::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $playlist->id, 'name' => 'G', 'name_internal' => 'G']);
    $defaults = ['user_id' => $this->user->id, 'playlist_id' => $playlist->id, 'group_id' => $group->id, 'group' => 'G', 'group_internal' => 'G', 'logo' => null, 'enabled' => false];

    Channel::factory()->create(['name' => 'plain', 'title' => 'plain'] + $defaults);
    Channel::factory()->create($attributes + ['name' => 'kept', 'title' => 'kept'] + $defaults);

    $export = decodeExport(file_get_contents(exportToTempFile($this->service, $playlist)));

    expect(collect($export['channel'] ?? [])->pluck('name')->all())->toBe(['kept']);
})->with([
    'custom name' => [['name_custom' => 'Renamed']],
    'custom logo' => [['logo' => 'http://logo.test/x.png']],
    'custom url' => [['url_custom' => 'http://other.test/stream']],
    'station id' => [['station_id' => '12345']],
    'moved to another group' => [['group' => 'Somewhere else']],
    'proxy enabled' => [['enable_proxy' => true]],
    'epg mapping disabled' => [['epg_map_enabled' => false]],
    'custom channel' => [['is_custom' => true]],
]);

it('imports an export as a new playlist for another user and re-links EPG mappings by url', function () {
    $path = exportToTempFile($this->service, makeExportablePlaylist($this->user));

    $friend = User::factory()->create();
    $friendEpg = Epg::factory()->create(['user_id' => $friend->id, 'name' => 'Other Name', 'url' => 'http://epg.test/guide.xml']);
    $friendEpgChannel = EpgChannel::factory()->create(['user_id' => $friend->id, 'epg_id' => $friendEpg->id, 'channel_id' => 'bbc.uk', 'name' => 'Different']);

    $imported = $this->service->importFromFile($path, $friend, 'Imported Playlist');

    expect($imported->user_id)->toBe($friend->id)
        ->and($imported->name)->toBe('Imported Playlist')
        ->and($imported->uuid)->not->toBeEmpty()
        ->and($imported->status)->toBe(Status::Completed)
        ->and($imported->processing)->toBeFalse()
        ->and($imported->url)->toBe('http://provider.test/get.php')
        ->and($imported->xtream_config['password'])->toBe('p')
        ->and($imported->import_prefs)->toBe(['selected_groups' => ['News']])
        ->and($imported->channels)->toBe(2)
        ->and($imported->sourceGroups()->pluck('name')->all())->toBe(['News']);

    $group = $imported->groups()->where('name', 'News')->sole();
    $mergedGroup = $imported->groups()->where('name', 'All News')->sole();
    expect($group->user_id)->toBe($friend->id)
        ->and($group->parent_id)->toBe($mergedGroup->id)
        ->and($mergedGroup->is_merged)->toBeTrue()
        ->and($mergedGroup->parent_id)->toBeNull();

    $bouquet = Bouquet::query()->where('playlist_id', $imported->id)->sole();
    expect($bouquet->user_id)->toBe($friend->id)
        ->and($bouquet->group_selections)->toBe(['live' => ['News']])
        ->and($bouquet->auto_include_new_live)->toBeTrue();

    $dynamicGroup = DynamicGroup::query()->where('playlist_id', $imported->id)->sole();
    expect($dynamicGroup->user_id)->toBe($friend->id)
        ->and($dynamicGroup->tmdb_params)->toBe(['list' => 'trending']);

    $primary = $imported->channels()->where('name', 'BBC')->sole();
    $backup = $imported->channels()->where('name', 'BBC Backup')->sole();
    expect($primary->user_id)->toBe($friend->id)
        ->and($primary->group_id)->toBe($group->id)
        ->and($primary->source_id)->toBe('src-bbc')
        ->and($primary->name_custom)->toBe('BBC One HD')
        ->and($primary->channel)->toBe(101)
        ->and($primary->extvlcopt)->toBe([['key' => 'http-user-agent', 'value' => 'VLC']])
        ->and($primary->epg_channel_id)->toBe($friendEpgChannel->id)
        ->and($primary->uuid)->not->toBeEmpty()
        ->and($backup->enabled)->toBeFalse()
        ->and($backup->epg_channel_id)->toBeNull();

    $failover = ChannelFailover::query()->where('channel_id', $primary->id)->sole();
    expect($failover->channel_failover_id)->toBe($backup->id)->and($failover->user_id)->toBe($friend->id);

    $category = $imported->categories()->sole();
    $series = $imported->series()->sole();
    $season = $imported->seasons()->sole();
    $episode = $imported->episodes()->sole();
    expect($series->name)->toBe('Show')
        ->and($series->category_id)->toBe($category->id)
        ->and($season->series_id)->toBe($series->id)
        ->and($season->category_id)->toBe($category->id)
        ->and($episode->series_id)->toBe($series->id)
        ->and($episode->season_id)->toBe($season->id)
        ->and($episode->title)->toBe('Pilot');

    // The source playlist is untouched, and neither the untouched channel nor the AIO clone travelled
    expect(Playlist::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(Channel::withoutGlobalScopes()->count())->toBe(6)
        ->and($imported->channels()->count())->toBe(2);
});

it('maps channels to an explicitly chosen EPG, falling back to the EPG channel name', function () {
    $path = exportToTempFile($this->service, makeExportablePlaylist($this->user));

    $friend = User::factory()->create();
    $chosen = Epg::factory()->create(['user_id' => $friend->id, 'name' => 'Chosen', 'url' => 'http://elsewhere.test/x.xml']);
    $byName = EpgChannel::factory()->create(['user_id' => $friend->id, 'epg_id' => $chosen->id, 'channel_id' => 'something-else', 'name' => 'BBC One']);

    $imported = $this->service->importFromFile($path, $friend, epg: $chosen);

    expect($imported->name)->toBe('Source Playlist')
        ->and($imported->channels()->where('name', 'BBC')->sole()->epg_channel_id)->toBe($byName->id);
});

it('leaves channels unmapped when no matching EPG exists', function () {
    $path = exportToTempFile($this->service, makeExportablePlaylist($this->user));
    $friend = User::factory()->create();

    $imported = $this->service->importFromFile($path, $friend);

    expect($imported->channels()->whereNotNull('epg_channel_id')->count())->toBe(0)
        ->and($imported->channels()->count())->toBe(2);
});

it('rejects files that are not playlist exports', function (string $contents, string $message) {
    $path = tempnam(sys_get_temp_dir(), 'bad-export-');
    file_put_contents($path, $contents);

    expect(fn () => $this->service->importFromFile($path, $this->user))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'invalid json' => ['{not json', 'invalid JSON'],
    'wrong format' => ['{"format":"something-else"}'."\n", 'not a playlist export'],
    'newer version' => ['{"format":"m3u-editor-playlist","version":999}'."\n", 'newer version'],
    'missing playlist' => ['{"format":"m3u-editor-playlist","version":2}'."\n".'{"type":"group","data":{"name":"x"}}'."\n", 'does not contain a playlist'],
    'header only' => [gzencode('{"format":"m3u-editor-playlist","version":2}'."\n"), 'does not contain a playlist'],
]);

it('imports the uploaded file via the job and deletes it afterwards', function () {
    Notification::fake();
    Storage::fake('local');
    $path = exportToTempFile($this->service, makeExportablePlaylist($this->user));
    Storage::disk('local')->put('playlist-imports/export.jsonl.gz', file_get_contents($path));

    $friend = User::factory()->create();

    (new ImportPlaylist($friend, 'playlist-imports/export.jsonl.gz', 'From Job'))->handle($this->service);

    $imported = Playlist::query()->where('user_id', $friend->id)->sole();
    expect($imported->name)->toBe('From Job')
        ->and($imported->channels()->count())->toBe(2)
        ->and(Storage::disk('local')->exists('playlist-imports/export.jsonl.gz'))->toBeFalse();

    Notification::assertSentTo($friend, DatabaseNotification::class, function (DatabaseNotification $notification) use ($friend): bool {
        return $notification->toDatabase($friend)['title'] === 'Playlist Imported';
    });
});

it('notifies the user when the uploaded file is not a valid export', function () {
    Notification::fake();
    Storage::fake('local');
    Storage::disk('local')->put('playlist-imports/bad.jsonl.gz', 'nope');
    $friend = User::factory()->create();

    (new ImportPlaylist($friend, 'playlist-imports/bad.jsonl.gz'))->handle($this->service);

    expect(Playlist::query()->where('user_id', $friend->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists('playlist-imports/bad.jsonl.gz'))->toBeFalse();

    Notification::assertSentTo($friend, DatabaseNotification::class, function (DatabaseNotification $notification) use ($friend): bool {
        $data = $notification->toDatabase($friend);

        return $data['title'] === 'Error importing playlist' && str_contains($data['body'], 'invalid JSON');
    });
});

it('dispatches the import job from the playlists list page', function () {
    Bus::fake();
    Storage::fake('local');
    $this->actingAs($this->user);
    $epg = Epg::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(ListPlaylists::class)
        ->callAction('import', [
            'file' => UploadedFile::fake()->createWithContent('export.jsonl.gz', gzencode('{"format":"m3u-editor-playlist","version":2}'."\n")),
            'name' => 'Friend Playlist',
            'epg_id' => $epg->id,
        ])
        ->assertHasNoActionErrors();

    Bus::assertDispatched(ImportPlaylist::class, function (ImportPlaylist $job) use ($epg): bool {
        return $job->user->is($this->user)
            && str_starts_with($job->path, 'playlist-imports/')
            && Storage::disk('local')->exists($job->path)
            && $job->name === 'Friend Playlist'
            && $job->epgId === $epg->id;
    });
});

it('links the export action to the download route', function () {
    $this->actingAs($this->user);
    $playlist = makeExportablePlaylist($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->getRouteKey()])
        ->assertActionExists('export')
        ->assertActionHasUrl('export', route('playlists.export', $playlist));
});

it('downloads the gzipped export from the dedicated route', function () {
    $playlist = makeExportablePlaylist($this->user);

    $response = $this->actingAs($this->user)->get(route('playlists.export', $playlist));

    $response->assertOk()
        ->assertHeader('content-type', 'application/gzip')
        ->assertDownload('source-playlist-playlist-export.jsonl.gz');

    $export = decodeExport($response->streamedContent());
    expect($export['playlist'][0]['name'])->toBe('Source Playlist')
        ->and($export['channel'])->toHaveCount(2);
});

it('refuses to export another users playlist', function () {
    $playlist = makeExportablePlaylist($this->user);
    $stranger = User::factory()->create();

    $this->get(route('playlists.export', $playlist))->assertRedirect();
    $this->actingAs($stranger)->get(route('playlists.export', $playlist))->assertForbidden();
});

it('imports channels across batch boundaries and links failovers between batches', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id, 'name' => 'Big']);
    $group = Group::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $playlist->id, 'name' => 'G', 'name_internal' => 'G']);

    $rows = [];
    for ($i = 1; $i <= 1100; $i++) {
        $rows[] = [
            'user_id' => $this->user->id,
            'playlist_id' => $playlist->id,
            'group_id' => $group->id,
            'group' => 'G',
            'group_internal' => 'G',
            'name' => "Channel {$i}",
            'title' => "Channel {$i}",
            'source_id' => "src-{$i}",
            'enabled' => true,
            'uuid' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        Channel::query()->insert($chunk);
    }
    $first = Channel::query()->where('playlist_id', $playlist->id)->where('name', 'Channel 1')->sole();
    $last = Channel::query()->where('playlist_id', $playlist->id)->where('name', 'Channel 1100')->sole();
    ChannelFailover::query()->create(['user_id' => $this->user->id, 'channel_id' => $first->id, 'channel_failover_id' => $last->id, 'sort' => 1]);

    $friend = User::factory()->create();
    $imported = $this->service->importFromFile(exportToTempFile($this->service, $playlist), $friend);

    $importedFirst = $imported->channels()->where('name', 'Channel 1')->sole();
    $importedLast = $imported->channels()->where('name', 'Channel 1100')->sole();
    expect($imported->channels()->count())->toBe(1100)
        ->and($imported->channels)->toBe(1100)
        ->and(ChannelFailover::query()->where('channel_id', $importedFirst->id)->sole()->channel_failover_id)->toBe($importedLast->id);
});
