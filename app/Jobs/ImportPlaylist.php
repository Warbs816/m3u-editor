<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\User;
use App\Services\PlaylistTransferService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ImportPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  string  $path  Path of the uploaded export file on the local disk.
     */
    public function __construct(
        public User $user,
        public string $path,
        public ?string $name = null,
        public ?int $epgId = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(PlaylistTransferService $transferService): void
    {
        $disk = Storage::disk('local');

        try {
            if (! $disk->exists($this->path)) {
                throw new Exception('The uploaded file could not be found.');
            }

            $epg = $this->epgId
                ? Epg::query()->where('user_id', $this->user->id)->find($this->epgId)
                : null;

            $playlist = $transferService->importFromFile($disk->path($this->path), $this->user, $this->name, $epg);

            $mapped = $playlist->channels()->whereNotNull('epg_channel_id')->count();

            Notification::make()
                ->success()
                ->title('Playlist Imported')
                ->body("\"{$playlist->name}\" has been imported with {$playlist->channels} channels ({$mapped} mapped to EPG).")
                ->broadcast($this->user);
            Notification::make()
                ->success()
                ->title('Playlist Imported')
                ->body("\"{$playlist->name}\" has been imported with {$playlist->channels} channels ({$mapped} mapped to EPG).")
                ->sendToDatabase($this->user);
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error importing playlist from \"{$this->path}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title('Error importing playlist')
                ->body('Please view your notifications for details.')
                ->broadcast($this->user);
            Notification::make()
                ->danger()
                ->title('Error importing playlist')
                ->body($e->getMessage())
                ->sendToDatabase($this->user);
        } finally {
            $disk->delete($this->path);
        }
    }
}
