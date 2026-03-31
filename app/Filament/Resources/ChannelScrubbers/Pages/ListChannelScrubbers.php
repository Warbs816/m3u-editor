<?php

namespace App\Filament\Resources\ChannelScrubbers\Pages;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use Filament\Actions\CreateAction;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Resources\Pages\ListRecords;

class ListChannelScrubbers extends ListRecords
{
    protected static string $resource = ChannelScrubberResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return __('Scrubber tasks run after Playlist sync to check for dead URLs and automatically disable failing channels based on the configuration.');
    }
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
