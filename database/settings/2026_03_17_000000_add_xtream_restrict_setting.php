<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.xtream_restrict_to_dedicated_port')) {
            $this->migrator->add('general.xtream_restrict_to_dedicated_port', false);
        }
    }
};
