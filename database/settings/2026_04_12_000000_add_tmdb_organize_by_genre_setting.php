<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tmdb_organize_by_genre')) {
            $this->migrator->add('general.tmdb_organize_by_genre', false);
        }
    }
};
