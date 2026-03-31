<?php

namespace App\Filament\Resources\PlaylistViewers\Pages;

use App\Filament\Resources\PlaylistViewers\PlaylistViewerResource;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistViewers extends ListRecords
{
    protected static string $resource = PlaylistViewerResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return __('Playlist viewers are used for in app viewing and M3U TV access. Viewers are created automatically via username used to access playlist or start playback.');
    }
    public function getHeaderActions(): array
    {
        return [];
    }
}
