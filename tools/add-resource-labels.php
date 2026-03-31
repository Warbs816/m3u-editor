<?php

/**
 * Adds getModelLabel() and getPluralModelLabel() methods to Filament resources
 * that rely on Filament's auto-inferred labels (not translatable).
 * Also converts $subheading instance properties to getSubheading() methods.
 */
$base = __DIR__.'/..';

// ── Resource label map: ResourceClass => [singular, plural] ──────────────────
$resourceLabels = [
    'AssetResource' => ['Asset',                     'Assets'],
    'CategoryResource' => ['Category',                  'Categories'],
    'ChannelScrubberResource' => ['Channel Scrubber',          'Channel Scrubbers'],
    'ChannelResource' => ['Channel',                   'Channels'],
    'CustomPlaylistResource' => ['Custom Playlist',           'Custom Playlists'],
    'EpgChannelResource' => ['EPG Channel',               'EPG Channels'],
    'EpgMapResource' => ['EPG Map',                   'EPG Maps'],
    'EpgResource' => ['EPG',                       'EPGs'],
    'GroupResource' => ['Group',                     'Groups'],
    'MediaServerIntegrationResource' => ['Media Server Integration',  'Media Server Integrations'],
    'MergedEpgResource' => ['Merged EPG',                'Merged EPGs'],
    'MergedPlaylistResource' => ['Merged Playlist',           'Merged Playlists'],
    'NetworkResource' => ['Network',                   'Networks'],
    'PersonalAccessTokenResource' => ['Personal Access Token',     'Personal Access Tokens'],
    'PlaylistAliasResource' => ['Playlist Alias',            'Playlist Aliases'],
    'PlaylistAuthResource' => ['Playlist Auth',             'Playlist Auths'],
    'PlaylistViewerResource' => ['Playlist Viewer',           'Playlist Viewers'],
    'PlaylistResource' => ['Playlist',                  'Playlists'],
    'PostProcessResource' => ['Post Process',              'Post Processing'],
    'SeriesResource' => ['Series',                    'Series'],
    'StreamFileSettingResource' => ['Stream File Setting',       'Stream File Settings'],
    'StreamProfileResource' => ['Stream Profile',            'Stream Profiles'],
    'UserResource' => ['User',                      'Users'],
    'VodGroupResource' => ['VOD Group',                 'VOD Groups'],
    'VodResource' => ['VOD',                       'VODs'],
];

// ── Step 1: Add label methods to Resource files ──────────────────────────────
$resourceFiles = glob($base.'/app/Filament/Resources/*/*.php');
$updatedResources = 0;
$skippedResources = 0;

foreach ($resourceFiles as $file) {
    $basename = basename($file, '.php');

    if (! isset($resourceLabels[$basename])) {
        continue;
    }

    // Skip sub-pages and RelationManagers
    if (str_contains($file, '/Pages/') || str_contains($file, '/RelationManagers/')) {
        continue;
    }

    [$singular, $plural] = $resourceLabels[$basename];
    $content = file_get_contents($file);

    // Skip if already has these methods
    if (str_contains($content, 'getModelLabel()') || str_contains($content, 'getPluralModelLabel()')) {
        echo "  SKIP (already has label methods): {$basename}\n";
        $skippedResources++;

        continue;
    }

    // Find the getNavigationGroup method to insert after it, or insert before form()
    $labelMethods = <<<PHP

    public static function getModelLabel(): string
    {
        return __('{$singular}');
    }

    public static function getPluralModelLabel(): string
    {
        return __('{$plural}');
    }
PHP;

    // Insert after the last static property block or before the first public static function
    if (preg_match('/(\n    public static function getNavigationGroup\(\)[^\}]+\})/s', $content, $m)) {
        // Insert right after getNavigationGroup()
        $content = str_replace($m[0], $m[0].$labelMethods, $content);
    } elseif (preg_match('/(\n    public static function form\()/s', $content)) {
        $content = preg_replace('/(\n    public static function form\()/', $labelMethods."\n\n".'    public static function form(', $content, 1);
    } else {
        echo "  WARN (no insertion point): {$basename}\n";

        continue;
    }

    file_put_contents($file, $content);
    echo "  DONE: {$basename} — '{$singular}' / '{$plural}'\n";
    $updatedResources++;
}

echo "\nResources updated: {$updatedResources}, skipped: {$skippedResources}\n\n";

// ── Step 2: Convert $subheading to getSubheading() on page files ─────────────
$pageFiles = glob($base.'/app/Filament/Resources/*/Pages/*.php');
$pageFiles = array_merge($pageFiles, glob($base.'/app/Filament/Pages/*.php'));

$updatedPages = 0;
$skippedPages = 0;

foreach ($pageFiles as $file) {
    $content = file_get_contents($file);

    // Match: protected ?string $subheading = '...'; (single-quoted, may span exactly one line)
    if (! preg_match('/^    protected \?string \$subheading = \'((?:[^\'\\\\]|\\\\.)*)\';\s*$/m', $content, $m)) {
        continue;
    }

    $original = $m[0];
    $text = $m[1];

    // Skip if something went wrong
    if (empty(trim($text))) {
        $skippedPages++;

        continue;
    }

    $escaped = addslashes($text);
    $replacement = <<<PHP
    public function getSubheading(): string|\\Illuminate\\Contracts\\Support\\Htmlable|null
    {
        return __('{$escaped}');
    }
PHP;

    $newContent = str_replace($original, $replacement, $content);

    if ($newContent === $content) {
        $skippedPages++;

        continue;
    }

    // Make sure the Htmlable import exists if needed
    if (! str_contains($newContent, 'use Illuminate\Contracts\Support\Htmlable;')) {
        $newContent = preg_replace('/(^use [^\n]+;\n)(?!use )/m', "use Illuminate\\Contracts\\Support\\Htmlable;\n$1", $newContent, 1);
    }

    file_put_contents($file, $newContent);
    echo '  DONE page: '.basename($file)."\n";
    $updatedPages++;
}

echo "\nPages updated: {$updatedPages}, skipped: {$skippedPages}\n";
