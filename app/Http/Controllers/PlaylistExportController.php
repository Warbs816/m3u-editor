<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Services\PlaylistTransferService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlaylistExportController extends Controller
{
    /**
     * Download a playlist export file as a real HTTP GET, outside the Livewire
     * request/response cycle. The export is produced and gzipped record by
     * record, so even a playlist with tens of thousands of channels streams
     * to the browser without being buffered in memory first.
     */
    public function __invoke(Request $request, Playlist $playlist, PlaylistTransferService $transferService): StreamedResponse
    {
        abort_unless($playlist->user_id === $request->user()->id, 403);

        return response()->streamDownload(function () use ($playlist, $transferService): void {
            $transferService->writeExport($playlist, function (string $bytes): void {
                echo $bytes;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });
        }, $transferService->exportFileName($playlist), [
            'Content-Type' => 'application/gzip',
        ]);
    }
}
