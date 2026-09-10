<?php

namespace App\Services;

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Mutable bookkeeping for one streamed playlist import: the playlist being
 * built, the file-key to database-id maps, and cross references that can only
 * be resolved once every row exists.
 */
class PlaylistImportState
{
    public ?Playlist $playlist = null;

    public string $batchNo;

    /** @var array<int, array{key: int, name: string, url: string|null}> */
    public array $epgs = [];

    /** @var array<int, int>|null exported epg key => local epg id, resolved lazily */
    public ?array $epgIds = null;

    /** @var array<int, int> */
    public array $groupIds = [];

    /** @var array<int, int> child key => parent key */
    public array $groupParents = [];

    /** @var array<int, int> */
    public array $categoryIds = [];

    /** @var array<int, int> child key => parent key */
    public array $categoryParents = [];

    /** @var array<int, int> */
    public array $seriesIds = [];

    /** @var array<int, int|null> new series id => new category id */
    public array $seriesCategoryIds = [];

    /** @var array<int, int> */
    public array $seasonIds = [];

    /** @var array<int, int> */
    public array $channelIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $channelBatch = [];

    /** @var array<int, array<string, mixed>> */
    public array $pendingFailovers = [];

    public function __construct(
        public readonly User $user,
        public readonly ?string $name = null,
        public readonly ?Epg $epg = null,
    ) {
        $this->batchNo = Str::orderedUuid()->toString();
    }
}
