<?php

namespace App\Filament\Concerns;

trait HasCopilotSupport
{
    public static function copilotResourceDescription(): ?string
    {
        return 'Manages '.static::getPluralModelLabel().' in the application.';
    }

    public static function copilotTools(): array
    {
        return [];
    }
}
