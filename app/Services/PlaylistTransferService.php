<?php

namespace App\Services;

use App\Enums\ChannelLogoType;
use App\Enums\Status;
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
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Exports a playlist (settings, bouquets, groups, channels, series and EPG
 * mappings) to a portable file, and imports such a file as a brand new
 * playlist.
 *
 * The file is gzipped JSON Lines: a header line followed by one JSON record
 * per line, each shaped {"type": "...", "data": {...}}. Both directions stream
 * record by record, so a playlist with tens of thousands of channels never has
 * to fit in memory at once. Records are written parents-first (playlist, EPGs,
 * groups, categories, series, seasons, channels, episodes) and the importer
 * relies on that order.
 *
 * Every database id is replaced by a file-local key on export and re-resolved
 * on import, so the file can be moved between instances and users. EPG
 * mappings are stored as the EPG channel's textual channel id and re-matched
 * against the importing user's EPGs.
 *
 * Only channels that carry something the next provider sync would not
 * recreate are exported: enabled channels, custom channels, channels with
 * user overrides or an EPG link, and both ends of every failover. A disabled
 * provider channel with nothing changed is left out, since the sync matches
 * channels by source id and recreates it identically.
 *
 * @phpstan-type TransferHeader array{format: string, version: int, app_version: string|null, exported_at: string}
 * @phpstan-type TransferRecord array{type: string, data: array<string, mixed>}
 */
class PlaylistTransferService
{
    public const FORMAT = 'm3u-editor-playlist';

    public const VERSION = 2;

    public const FILE_EXTENSION = 'jsonl.gz';

    /** Channels are imported in batches of this size so EPG lookups are grouped. */
    protected const CHANNEL_BATCH = 500;

    /**
     * Column listings per table, cached for the lifetime of the service.
     *
     * @var array<string, array<string, int>>
     */
    protected array $columns = [];

    /**
     * Playlist columns that are instance or user specific and must never travel.
     *
     * @var list<string>
     */
    protected const PLAYLIST_EXCLUDED = [
        'id', 'uuid', 'user_id', 'status', 'errors', 'synced', 'sync_time', 'progress',
        'series_progress', 'vod_progress', 'processing', 'uploads', 'xtream_status',
        'short_urls_enabled', 'short_urls', 'channels', 'available_streams',
        'stream_profile_id', 'vod_stream_profile_id', 'auto_retry_503_count',
        'auto_retry_503_last_at', 'aiostreams_integration_id', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const GROUP_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'parent_id', 'stream_profile_id', 'stream_file_setting_id',
        'aed_profile_id', 'import_batch_no', 'deleted_at', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const CHANNEL_EXCLUDED = [
        'id', 'uuid', 'user_id', 'playlist_id', 'group_id', 'epg_channel_id',
        'custom_playlist_id', 'network_id', 'dvr_recording_id', 'stream_profile_id',
        'stream_file_setting_id', 'aed_profile_id', 'aio_integration_id', 'aio_resolution_status',
        'aio_last_resolved_at', 'import_batch_no', 'sync_location', 'last_metadata_fetch',
        'last_scrubbed_at', 'last_scrubber_live', 'stream_stats', 'stream_stats_probed_at',
        'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const CATEGORY_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'parent_id', 'stream_file_setting_id', 'import_batch_no',
        'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const SERIES_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'category_id', 'stream_file_setting_id',
        'aio_integration_id', 'aio_resolution_status', 'aio_last_resolved_at',
        'import_batch_no', 'sync_location', 'last_metadata_fetch', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const SEASON_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'category_id', 'series_id', 'import_batch_no',
        'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const EPISODE_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'series_id', 'season_id', 'dvr_recording_id',
        'aio_resolution_status', 'aio_last_resolved_at', 'import_batch_no',
        'stream_stats', 'stream_stats_probed_at', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const BOUQUET_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'custom_playlist_id', 'merged_playlist_id',
        'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const DYNAMIC_GROUP_EXCLUDED = [
        'id', 'user_id', 'playlist_id', 'last_synced_at', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    protected const SOURCE_EXCLUDED = ['id', 'playlist_id', 'created_at', 'updated_at'];

    /**
     * Write the gzipped export of a playlist through the given sink.
     *
     * @param  callable(string): void  $write  Receives compressed bytes as they are produced.
     */
    public function writeExport(Playlist $playlist, callable $write): void
    {
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        foreach ($this->exportRecords($playlist) as $record) {
            $write(deflate_add($deflate, $this->encodeLine($record), ZLIB_NO_FLUSH));
        }

        $write(deflate_add($deflate, '', ZLIB_FINISH));
    }

    /**
     * The file name an export of the playlist should be downloaded as.
     */
    public function exportFileName(Playlist $playlist): string
    {
        return Str::slug($playlist->name).'-playlist-export.'.self::FILE_EXTENSION;
    }

    /**
     * Yield the export as a header followed by typed records, parents first.
     *
     * @return Generator<int, TransferHeader|TransferRecord>
     */
    public function exportRecords(Playlist $playlist): Generator
    {
        yield [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'app_version' => config('dev.version'),
            'exported_at' => now()->toIso8601String(),
        ];

        yield $this->record('playlist', $this->attributesExcept($playlist, self::PLAYLIST_EXCLUDED));

        $epgIds = EpgChannel::query()
            ->whereIn('id', $playlist->channels()->whereNotNull('epg_channel_id')->select('epg_channel_id'))
            ->distinct()
            ->pluck('epg_id');
        foreach (Epg::query()->whereIn('id', $epgIds)->orderBy('id')->cursor() as $epg) {
            yield $this->record('epg', ['key' => $epg->id, 'name' => $epg->name, 'url' => $epg->url]);
        }

        foreach ($playlist->sourceGroups()->orderBy('id')->cursor() as $source) {
            yield $this->record('source_group', $this->attributesExcept($source, self::SOURCE_EXCLUDED));
        }
        foreach ($playlist->sourceCategories()->orderBy('id')->cursor() as $source) {
            yield $this->record('source_category', $this->attributesExcept($source, self::SOURCE_EXCLUDED));
        }
        foreach ($playlist->bouquets()->orderBy('id')->cursor() as $bouquet) {
            yield $this->record('bouquet', $this->attributesExcept($bouquet, self::BOUQUET_EXCLUDED));
        }
        foreach ($playlist->dynamicGroups()->orderBy('id')->cursor() as $dynamicGroup) {
            yield $this->record('dynamic_group', $this->attributesExcept($dynamicGroup, self::DYNAMIC_GROUP_EXCLUDED));
        }

        foreach ($playlist->groups()->orderBy('id')->cursor() as $group) {
            yield $this->record('group', [
                'key' => $group->id,
                'parent_key' => $group->parent_id,
            ] + $this->attributesExcept($group, self::GROUP_EXCLUDED));
        }

        foreach ($playlist->categories()->orderBy('id')->cursor() as $category) {
            yield $this->record('category', [
                'key' => $category->id,
                'parent_key' => $category->parent_id,
            ] + $this->attributesExcept($category, self::CATEGORY_EXCLUDED));
        }

        $enabledSeries = $playlist->series()->where('enabled', true);
        foreach ((clone $enabledSeries)->orderBy('id')->cursor() as $series) {
            yield $this->record('series', [
                'key' => $series->id,
                'category_key' => $series->category_id,
            ] + $this->attributesExcept($series, self::SERIES_EXCLUDED));
        }
        foreach ($playlist->seasons()->whereIn('series_id', (clone $enabledSeries)->select('id'))->orderBy('id')->cursor() as $season) {
            yield $this->record('season', [
                'key' => $season->id,
                'series_key' => $season->series_id,
            ] + $this->attributesExcept($season, self::SEASON_EXCLUDED));
        }

        $failoverTargetIds = ChannelFailover::query()
            ->whereIn('channel_id', $playlist->channels()->select('id'))
            ->pluck('channel_failover_id')
            ->flip()
            ->all();
        $channels = $playlist->channels()
            ->with(['failovers', 'epgChannel:id,epg_id,name,channel_id'])
            ->lazyById(1000);
        foreach ($channels as $channel) {
            if ($this->shouldExportChannel($channel, $failoverTargetIds)) {
                yield $this->record('channel', $this->exportChannel($channel));
            }
        }

        foreach ($playlist->episodes()->whereIn('series_id', (clone $enabledSeries)->select('id'))->lazyById(1000) as $episode) {
            yield $this->record('episode', [
                'series_key' => $episode->series_id,
                'season_key' => $episode->season_id,
            ] + $this->attributesExcept($episode, self::EPISODE_EXCLUDED));
        }
    }

    /**
     * Create a new playlist for the user from an export file (gzipped or plain).
     *
     * When $epg is given every exported EPG mapping is resolved against that
     * EPG. Otherwise the user's EPGs are matched by URL, then by name.
     *
     * @throws InvalidArgumentException when the file is not a playlist export
     */
    public function importFromFile(string $path, User $user, ?string $name = null, ?Epg $epg = null): Playlist
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The export file could not be opened.');
        }

        try {
            $this->readHeader($handle);

            return DB::transaction(function () use ($handle, $user, $name, $epg): Playlist {
                $import = new PlaylistImportState($user, $name, $epg);

                while (($line = gzgets($handle)) !== false) {
                    if (trim($line) === '') {
                        continue;
                    }
                    $record = $this->decodeLine($line);
                    $this->importRecord($record, $import);
                }

                return $this->finishImport($import);
            });
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Validate the first line of an export file.
     *
     * @param  resource  $handle
     * @return TransferHeader
     */
    protected function readHeader($handle): array
    {
        $line = gzgets($handle);
        $header = $line === false ? null : $this->decodeLine($line);

        if (! is_array($header) || ($header['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('The file is not a playlist export.');
        }

        if ((int) ($header['version'] ?? 0) > self::VERSION) {
            throw new InvalidArgumentException('The file was created by a newer version of the app and cannot be imported.');
        }

        return $header;
    }

    /**
     * @param  TransferRecord  $record
     */
    protected function importRecord(array $record, PlaylistImportState $import): void
    {
        $type = $record['type'] ?? null;
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];

        if ($type !== 'playlist' && $import->playlist === null) {
            throw new InvalidArgumentException('The file does not contain a playlist.');
        }

        match ($type) {
            'playlist' => $this->importPlaylist($data, $import),
            'epg' => $import->epgs[] = $data,
            'source_group' => $this->importOwned(new SourceGroup, $data, $import, withUser: false),
            'source_category' => $this->importOwned(new SourceCategory, $data, $import, withUser: false),
            'bouquet' => $this->importOwned(new Bouquet, $data, $import), // saveQuietly bypasses the Bouquet::saving ownership guard, user_id is set explicitly
            'dynamic_group' => $this->importOwned(new DynamicGroup, $data, $import),
            'group' => $this->importGroup($data, $import),
            'category' => $this->importCategory($data, $import),
            'series' => $this->importSeries($data, $import),
            'season' => $this->importSeason($data, $import),
            'channel' => $this->bufferChannel($data, $import),
            'episode' => $this->importEpisode($data, $import),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importPlaylist(array $data, PlaylistImportState $import): void
    {
        if ($import->playlist !== null || blank($data['name'] ?? null)) {
            throw new InvalidArgumentException('The file does not contain a playlist.');
        }

        $now = now();
        $playlist = new Playlist;
        $this->fillTransferred($playlist, $data);
        $playlist->forceFill([
            'name' => $import->name ?: $data['name'],
            'uuid' => Str::orderedUuid()->toString(),
            'user_id' => $import->user->id,
            'status' => Status::Completed,
            'processing' => false,
            'synced' => $now,
            'errors' => null,
            'progress' => 100,
            'uploads' => null,
            'short_urls_enabled' => false,
            'short_urls' => null,
            'xtream_status' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $playlist->saveQuietly(); // Don't fire model events to prevent auto sync

        $import->playlist = $playlist;
    }

    /**
     * Import a simple child row that only needs the new owner and playlist.
     *
     * @param  array<string, mixed>  $data
     */
    protected function importOwned(Model $model, array $data, PlaylistImportState $import, bool $withUser = true): void
    {
        $this->fillTransferred($model, $data);
        $model->forceFill(array_filter([
            'user_id' => $withUser ? $import->user->id : null,
            'playlist_id' => $import->playlist->id,
        ]));
        $model->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importGroup(array $data, PlaylistImportState $import): void
    {
        $group = new Group;
        $this->fillTransferred($group, ['import_batch_no' => $import->batchNo] + $data, ['key', 'parent_key']);
        $group->forceFill(['user_id' => $import->user->id, 'playlist_id' => $import->playlist->id]);
        $group->saveQuietly();

        $import->groupIds[$data['key']] = $group->id;
        if (($data['parent_key'] ?? null) !== null) {
            $import->groupParents[$data['key']] = $data['parent_key'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importCategory(array $data, PlaylistImportState $import): void
    {
        $category = new Category;
        $this->fillTransferred($category, ['import_batch_no' => $import->batchNo] + $data, ['key', 'parent_key']);
        $category->forceFill(['user_id' => $import->user->id, 'playlist_id' => $import->playlist->id]);
        $category->saveQuietly();

        $import->categoryIds[$data['key']] = $category->id;
        if (($data['parent_key'] ?? null) !== null) {
            $import->categoryParents[$data['key']] = $data['parent_key'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importSeries(array $data, PlaylistImportState $import): void
    {
        $series = new Series;
        $this->fillTransferred($series, ['import_batch_no' => $import->batchNo] + $data, ['key', 'category_key']);
        $series->forceFill([
            'user_id' => $import->user->id,
            'playlist_id' => $import->playlist->id,
            'category_id' => $import->categoryIds[$data['category_key'] ?? null] ?? null,
        ]);
        $series->saveQuietly();

        $import->seriesIds[$data['key']] = $series->id;
        $import->seriesCategoryIds[$series->id] = $series->category_id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importSeason(array $data, PlaylistImportState $import): void
    {
        $seriesId = $import->seriesIds[$data['series_key'] ?? null] ?? null;
        if ($seriesId === null) {
            return;
        }

        $season = new Season;
        $this->fillTransferred($season, ['import_batch_no' => $import->batchNo] + $data, ['key', 'series_key']);
        $season->forceFill([
            'user_id' => $import->user->id,
            'playlist_id' => $import->playlist->id,
            'series_id' => $seriesId,
            'category_id' => $import->seriesCategoryIds[$seriesId] ?? null,
        ]);
        $season->saveQuietly();

        $import->seasonIds[$data['key']] = $season->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function importEpisode(array $data, PlaylistImportState $import): void
    {
        $seriesId = $import->seriesIds[$data['series_key'] ?? null] ?? null;
        if ($seriesId === null) {
            return;
        }

        $episode = new Episode;
        $this->fillTransferred($episode, ['import_batch_no' => $import->batchNo] + $data, ['series_key', 'season_key']);
        $episode->forceFill([
            'user_id' => $import->user->id,
            'playlist_id' => $import->playlist->id,
            'series_id' => $seriesId,
            'season_id' => $import->seasonIds[$data['season_key'] ?? null] ?? null,
        ]);
        $episode->saveQuietly();
    }

    /**
     * Channels are collected into batches so their EPG channels can be looked
     * up with a handful of queries per batch instead of one per channel.
     *
     * @param  array<string, mixed>  $data
     */
    protected function bufferChannel(array $data, PlaylistImportState $import): void
    {
        $import->channelBatch[] = $data;

        if (count($import->channelBatch) >= self::CHANNEL_BATCH) {
            $this->flushChannelBatch($import);
        }
    }

    protected function flushChannelBatch(PlaylistImportState $import): void
    {
        if ($import->channelBatch === []) {
            return;
        }

        $epgChannelIds = $this->resolveEpgChannels($import->channelBatch, $this->resolveEpgs($import));

        foreach ($import->channelBatch as $data) {
            $channel = new Channel;
            $this->fillTransferred($channel, ['import_batch_no' => $import->batchNo] + $data, ['key', 'group_key', 'epg', 'failovers']);
            $channel->forceFill([
                'uuid' => Str::orderedUuid()->toString(),
                'user_id' => $import->user->id,
                'playlist_id' => $import->playlist->id,
                'group_id' => $import->groupIds[$data['group_key'] ?? null] ?? null,
                'epg_channel_id' => $this->lookupEpgChannel($data['epg'] ?? null, $epgChannelIds),
            ]);
            $channel->saveQuietly();

            $import->channelIds[$data['key']] = $channel->id;
            foreach ($data['failovers'] ?? [] as $failover) {
                $import->pendingFailovers[] = ['channel_id' => $channel->id] + $failover;
            }
        }

        $import->channelBatch = [];
    }

    /**
     * Flush the last channel batch and resolve every cross reference that
     * needed all rows to exist first: failovers and merged-group parents.
     */
    protected function finishImport(PlaylistImportState $import): Playlist
    {
        if ($import->playlist === null) {
            throw new InvalidArgumentException('The file does not contain a playlist.');
        }

        $this->flushChannelBatch($import);

        foreach ($import->pendingFailovers as $failover) {
            $targetId = $import->channelIds[$failover['channel_key'] ?? null] ?? null;
            if ($targetId === null) {
                continue;
            }
            ChannelFailover::query()->create([
                'user_id' => $import->user->id,
                'channel_id' => $failover['channel_id'],
                'channel_failover_id' => $targetId,
                'sort' => $failover['sort'] ?? null,
                'metadata' => $failover['metadata'] ?? null,
            ]);
        }

        $this->linkParents(Group::class, $import->groupParents, $import->groupIds);
        $this->linkParents(Category::class, $import->categoryParents, $import->categoryIds);

        $import->playlist->channels = count($import->channelIds);
        $import->playlist->saveQuietly();

        return $import->playlist;
    }

    /**
     * Second pass for merged groups/categories: point each imported child at
     * its imported parent using the file-local keys.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, int>  $parents  child key => parent key
     * @param  array<int, int>  $ids  file key => new id
     */
    protected function linkParents(string $modelClass, array $parents, array $ids): void
    {
        foreach ($parents as $childKey => $parentKey) {
            $parentId = $ids[$parentKey] ?? null;
            $childId = $ids[$childKey] ?? null;
            if ($parentId === null || $childId === null) {
                continue;
            }
            $modelClass::query()->whereKey($childId)->update(['parent_id' => $parentId]);
        }
    }

    /**
     * Whether the next provider sync would fail to recreate this channel as it
     * is now. Anything the user changed, linked or enabled must travel; a
     * disabled provider channel with nothing changed does not.
     *
     * @param  array<int, int>  $failoverTargetIds  ids of channels used as a failover by another channel
     */
    protected function shouldExportChannel(Channel $channel, array $failoverTargetIds): bool
    {
        return $channel->enabled
            || $channel->is_custom
            || $channel->failovers->isNotEmpty()
            || isset($failoverTargetIds[$channel->id])
            || $channel->epg_channel_id !== null
            || filled($channel->station_id)
            || filled($channel->tvg_shift)
            || filled($channel->tvg_type)
            || filled($channel->title_custom)
            || filled($channel->name_custom)
            || filled($channel->url_custom)
            || filled($channel->stream_id_custom)
            || filled($channel->logo)
            || $channel->logo_type === ChannelLogoType::Epg
            || ($channel->group ?? '') !== ($channel->group_internal ?? '')
            || $channel->enable_proxy
            || ! $channel->epg_map_enabled
            || ! $channel->probe_enabled
            || ! $channel->can_merge;
    }

    /**
     * @return array<string, mixed>
     */
    protected function exportChannel(Channel $channel): array
    {
        $data = ['key' => $channel->id, 'group_key' => $channel->group_id]
            + $this->attributesExcept($channel, self::CHANNEL_EXCLUDED);

        $data['epg'] = $channel->epgChannel ? [
            'epg_key' => $channel->epgChannel->epg_id,
            'channel_id' => $channel->epgChannel->channel_id,
            'name' => $channel->epgChannel->name,
        ] : null;

        $data['failovers'] = $channel->failovers
            ->map(fn (ChannelFailover $failover): array => [
                'channel_key' => $failover->channel_failover_id,
                'sort' => $failover->sort,
                'metadata' => $failover->metadata,
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * Resolve every EPG channel referenced by a batch of channels to an id on this instance.
     *
     * Returns a map of "epg_key|id:channel_id" and "epg_key|name:name" => epg_channel id.
     *
     * @param  array<int, array<string, mixed>>  $channels
     * @param  array<int, int>  $epgIds  exported epg key => local epg id
     * @return array<string, int>
     */
    protected function resolveEpgChannels(array $channels, array $epgIds): array
    {
        if ($epgIds === []) {
            return [];
        }

        $wanted = [];
        foreach ($channels as $channel) {
            $ref = $channel['epg'] ?? null;
            if (! is_array($ref) || ! isset($epgIds[$ref['epg_key'] ?? null])) {
                continue;
            }
            $wanted[$ref['epg_key']][] = $ref;
        }

        $resolved = [];
        foreach ($wanted as $epgKey => $refs) {
            $targetEpgId = $epgIds[$epgKey];

            $byChannelId = collect($refs)->pluck('channel_id')->filter()->unique();
            if ($byChannelId->isNotEmpty()) {
                EpgChannel::query()
                    ->where('epg_id', $targetEpgId)
                    ->whereIn('channel_id', $byChannelId->all())
                    ->orderBy('id')
                    ->get(['id', 'channel_id'])
                    ->each(function (EpgChannel $epgChannel) use (&$resolved, $epgKey): void {
                        $resolved["{$epgKey}|id:{$epgChannel->channel_id}"] ??= $epgChannel->id;
                    });
            }

            $byName = collect($refs)
                ->filter(fn (array $ref): bool => blank($ref['channel_id'] ?? null) || ! isset($resolved["{$epgKey}|id:{$ref['channel_id']}"]))
                ->pluck('name')
                ->filter()
                ->unique();
            if ($byName->isNotEmpty()) {
                EpgChannel::query()
                    ->where('epg_id', $targetEpgId)
                    ->whereIn('name', $byName->all())
                    ->orderBy('id')
                    ->get(['id', 'name'])
                    ->each(function (EpgChannel $epgChannel) use (&$resolved, $epgKey): void {
                        $resolved["{$epgKey}|name:{$epgChannel->name}"] ??= $epgChannel->id;
                    });
            }
        }

        return $resolved;
    }

    /**
     * Match the exported EPGs to the importing user's EPGs, memoised per import.
     *
     * @return array<int, int> exported epg key => local epg id
     */
    protected function resolveEpgs(PlaylistImportState $import): array
    {
        if ($import->epgIds !== null) {
            return $import->epgIds;
        }

        if ($import->epg !== null) {
            return $import->epgIds = collect($import->epgs)
                ->mapWithKeys(fn (array $exported): array => [(int) $exported['key'] => $import->epg->id])
                ->all();
        }

        $local = Epg::query()->where('user_id', $import->user->id)->get(['id', 'name', 'url']);

        $resolved = [];
        foreach ($import->epgs as $exported) {
            $match = null;
            if (filled($exported['url'] ?? null)) {
                $match = $local->first(fn (Epg $candidate): bool => $candidate->url === $exported['url']);
            }
            $match ??= $local->first(fn (Epg $candidate): bool => $candidate->name === ($exported['name'] ?? null));
            if ($match) {
                $resolved[(int) $exported['key']] = $match->id;
            }
        }

        return $import->epgIds = $resolved;
    }

    /**
     * @param  array{epg_key: int, channel_id: string|null, name: string|null}|null  $ref
     * @param  array<string, int>  $epgChannelIds
     */
    protected function lookupEpgChannel(?array $ref, array $epgChannelIds): ?int
    {
        if ($ref === null) {
            return null;
        }

        $epgKey = $ref['epg_key'] ?? null;

        return $epgChannelIds["{$epgKey}|id:".($ref['channel_id'] ?? '')]
            ?? $epgChannelIds["{$epgKey}|name:".($ref['name'] ?? '')]
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TransferRecord
     */
    protected function record(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function encodeLine(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeLine(string $line): array
    {
        try {
            $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The file is not a valid playlist export (invalid JSON).', previous: $e);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The file is not a valid playlist export.');
        }

        return $value;
    }

    /**
     * Fill a fresh model with transferred data, dropping file-local keys and
     * any column this instance's schema does not know about.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $drop
     */
    protected function fillTransferred(Model $model, array $data, array $drop = []): void
    {
        $table = $model->getTable();
        $this->columns[$table] ??= array_flip(Schema::getColumnListing($table));

        $model->forceFill(
            array_intersect_key(array_diff_key($data, array_flip($drop)), $this->columns[$table])
        );
    }

    /**
     * Raw column values of a model minus the given columns.
     *
     * Values are read after casting so JSON columns are exported as arrays,
     * enums as their backing value and dates as ISO strings.
     *
     * @param  list<string>  $excluded
     * @return array<string, mixed>
     */
    protected function attributesExcept(Model $model, array $excluded): array
    {
        return collect($model->attributesToArray())
            ->except($excluded)
            ->all();
    }
}
