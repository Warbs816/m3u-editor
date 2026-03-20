<?php

namespace App\Enums;

enum ChannelLogoType: string
{
    case Channel = 'channel';
    case Epg = 'epg';
    case Asset = 'asset';

    public function getColor(): string
    {
        return match ($this) {
            self::Channel => 'success',
            self::Epg => 'gray',
            self::Asset => 'info',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Channel => 'Channel',
            self::Epg => 'EPG',
            self::Asset => 'Image Asset',
        };
    }
}
