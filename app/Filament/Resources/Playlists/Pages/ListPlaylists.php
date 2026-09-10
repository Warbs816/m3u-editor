<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\ImportPlaylist;
use App\Models\Epg;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPlaylists extends ListRecords
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('Import Playlist'))
                ->schema([
                    FileUpload::make('file')
                        ->required()
                        ->label(__('Playlist export file (.jsonl.gz)'))
                        ->helperText(__('Select a playlist export file created by the "Export" action on another m3u editor instance.'))
                        ->moveFiles()
                        ->disk('local')
                        ->directory('playlist-imports')
                        ->acceptedFileTypes(['application/gzip', 'application/x-gzip', 'application/octet-stream', 'application/json', 'text/plain'])
                        ->maxSize(1024000),
                    TextInput::make('name')
                        ->label(__('Playlist name'))
                        ->helperText(__('Leave empty to keep the name stored in the file.')),
                    Select::make('epg_id')
                        ->label(__('EPG for channel mappings'))
                        ->helperText(__('Map the imported channels to this EPG. Leave empty to match the EPGs stored in the file by URL or name.'))
                        ->options(fn (): array => Epg::query()->where('user_id', auth()->id())->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new ImportPlaylist(
                            user: auth()->user(),
                            path: $data['file'],
                            name: filled($data['name'] ?? null) ? $data['name'] : null,
                            epgId: filled($data['epg_id'] ?? null) ? (int) $data['epg_id'] : null,
                        ));
                })->after(function (): void {
                    Notification::make()
                        ->success()
                        ->title(__('Playlist is being imported'))
                        ->body(__('Playlist is being imported in the background. You will be notified on completion.'))
                        ->duration(3000)
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalIcon('heroicon-o-arrow-down-tray')
                ->modalDescription(__('Import a playlist from an export file. A new playlist will be created, existing playlists are not changed.'))
                ->modalSubmitActionLabel(__('Import now')),
            CreateAction::make(),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
