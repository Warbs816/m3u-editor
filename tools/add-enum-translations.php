<?php

$json = file_get_contents(__DIR__.'/../lang/en.json');
$data = json_decode($json, true);

$new = [
    // PlaylistSourceType
    'Xtream' => 'Xtream',
    'M3U' => 'M3U',
    'Local File' => 'Local File',
    'Emby' => 'Emby',
    'Jellyfin' => 'Jellyfin',
    'Plex' => 'Plex',
    'Local Media' => 'Local Media',
    // Status
    'Pending' => 'Pending',
    'Processing' => 'Processing',
    'Completed' => 'Completed',
    'Failed' => 'Failed',
    'Cancelled' => 'Cancelled',
    // EpgSourceType
    'URL/XML File' => 'URL/XML File',
    'SchedulesDirect' => 'SchedulesDirect',
    // TranscodeMode
    'Direct' => 'Direct',
    'Server' => 'Server',
    'Local' => 'Local',
    // ChannelLogoType
    'Channel' => 'Channel',
    'EPG' => 'EPG',
    // PlaylistChannelId
    'TVG ID/Stream ID (default)' => 'TVG ID/Stream ID (default)',
    'Channel ID' => 'Channel ID',
    'Channel Name' => 'Channel Name',
    'Channel Number' => 'Channel Number',
    'Channel Title' => 'Channel Title',
];

$added = 0;
foreach ($new as $k => $v) {
    if (! array_key_exists($k, $data)) {
        $data[$k] = $v;
        $added++;
    }
}

file_put_contents(__DIR__.'/../lang/en.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Added $added new keys to lang/en.json\n";
echo 'Total keys: '.count($data)."\n";
