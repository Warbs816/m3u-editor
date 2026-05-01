<?php

/**
 * Tests for ProcessVodChannelsComplete job dispatch ordering.
 *
 * Verifies that SyncCompleted fires AFTER the full VOD pipeline
 * (including STRM sync) rather than before it starts — fixing the
 * race condition reported in issue #1083.
 *
 * Also verifies that when series import is also running (fireSyncCompleted=false),
 * SyncCompleted is NOT fired by the VOD pipeline to avoid double-firing.
 */

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\FetchTmdbIds;
use App\Jobs\FireSyncCompletedEvent;
use App\Jobs\ProcessM3uImportVod;
use App\Jobs\ProcessVodChannelsComplete;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function mockVodCompleteSettings(bool $tmdbAutoLookup): GeneralSettings
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdbAutoLookup;

    app()->instance(GeneralSettings::class, $mock);

    return $mock;
}

beforeEach(function () {
    Bus::fake();
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'auto_sync_vod_stream_files' => false,
        'find_replace_rules' => null,
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessVodChannelsComplete: SyncCompleted fired directly when no async post-jobs
// ──────────────────────────────────────────────────────────────────────────────

it('fires SyncCompleted directly when no TMDB lookup or STRM sync is configured', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist);
    $job->handle(app(GeneralSettings::class));

    Event::assertDispatched(SyncCompleted::class, fn (SyncCompleted $e) => $e->model->id === $this->playlist->id);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
});

it('does not fire SyncCompleted or chain it when fireSyncCompleted is false and no post-jobs', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist, fireSyncCompleted: false);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessVodChannelsComplete: SyncCompleted deferred to end of chain
// ──────────────────────────────────────────────────────────────────────────────

it('chains FireSyncCompletedEvent after SyncVodStrmFiles when STRM sync is enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([SyncVodStrmFiles::class, FireSyncCompletedEvent::class]);
});

it('chains FireSyncCompletedEvent after FetchTmdbIds when only TMDB lookup is enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: true);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([FetchTmdbIds::class, FireSyncCompletedEvent::class]);
});

it('chains FireSyncCompletedEvent last when both TMDB lookup and STRM sync are enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: true);

    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([FetchTmdbIds::class, SyncVodStrmFiles::class, FireSyncCompletedEvent::class]);
});

it('chains FindReplace before SyncVodStrmFiles and FireSyncCompletedEvent last when find-replace rules exist', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $this->playlist->update([
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
    ]);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([RunPlaylistFindReplaceRules::class, SyncVodStrmFiles::class, FireSyncCompletedEvent::class]);
});

it('does not chain FireSyncCompletedEvent when fireSyncCompleted is false, even with STRM sync enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $job = new ProcessVodChannelsComplete(playlist: $this->playlist, fireSyncCompleted: false);
    $job->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
    Bus::assertDispatched(SyncVodStrmFiles::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessM3uImportVod: STRM-only path (no metadata fetch)
// ──────────────────────────────────────────────────────────────────────────────

it('chains FireSyncCompletedEvent after SyncVodStrmFiles in the STRM-only path', function () {
    $this->playlist->update([
        'auto_fetch_vod_metadata' => false,
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => null,
    ]);

    $job = new ProcessM3uImportVod(
        playlist: $this->playlist,
        isNew: false,
        batchNo: 'test-batch',
        fireSyncCompleted: true,
    );
    $job->handle();

    Bus::assertChained([SyncVodStrmFiles::class, FireSyncCompletedEvent::class]);
});

it('does not chain FireSyncCompletedEvent in the STRM-only path when fireSyncCompleted is false', function () {
    $this->playlist->update([
        'auto_fetch_vod_metadata' => false,
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => null,
    ]);

    $job = new ProcessM3uImportVod(
        playlist: $this->playlist,
        isNew: false,
        batchNo: 'test-batch',
        fireSyncCompleted: false,
    );
    $job->handle();

    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
    Bus::assertDispatched(SyncVodStrmFiles::class);
});

it('chains FindReplace then SyncVodStrmFiles then FireSyncCompletedEvent in STRM-only path with find-replace rules', function () {
    $this->playlist->update([
        'auto_fetch_vod_metadata' => false,
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
    ]);

    $job = new ProcessM3uImportVod(
        playlist: $this->playlist,
        isNew: false,
        batchNo: 'test-batch',
        fireSyncCompleted: true,
    );
    $job->handle();

    Bus::assertChained([RunPlaylistFindReplaceRules::class, SyncVodStrmFiles::class, FireSyncCompletedEvent::class]);
});

// ──────────────────────────────────────────────────────────────────────────────
// FireSyncCompletedEvent: fires SyncCompleted for the correct playlist
// ──────────────────────────────────────────────────────────────────────────────

it('FireSyncCompletedEvent fires SyncCompleted for its playlist', function () {
    Event::fake();

    $job = new FireSyncCompletedEvent(playlist: $this->playlist);
    $job->handle();

    Event::assertDispatched(SyncCompleted::class, fn (SyncCompleted $e) => $e->model->id === $this->playlist->id);
});
