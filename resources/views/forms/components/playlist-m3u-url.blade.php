<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($record = $getRecord())
    @php($urls = \App\Facades\PlaylistFacade::getUrls($record))
    @php($m3uUrl = $urls['m3u'])
    @php($hdhrUrl = $urls['hdhr'])
    @php($xtreamRestricted = config('xtream.enabled') && app(\App\Settings\GeneralSettings::class)->xtream_restrict_to_dedicated_port)
    <div x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }">
        @if($xtreamRestricted)
            <div class="rounded-lg bg-warning-50 dark:bg-warning-400/10 p-3 text-sm text-warning-600 dark:text-warning-400 ring-1 ring-warning-600/10 dark:ring-warning-400/20 mb-4">
                These URLs use the dedicated Xtream port ({{ config('xtream.port') }}). They are only accessible on that port.
            </div>
        @endif
        <div class="flex gap-2 items-center justify-start mb-4">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                   <x-copy-to-clipboard :text="$m3uUrl" />
                </x-slot> 
                <x-filament::input
                    type="text"
                    :value="$m3uUrl"
                    readonly
                />
                <x-slot name="suffix">
                    .m3u
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="M3U URL" :text="$m3uUrl" />
        </div>
        <div class="flex gap-2 items-center justify-start">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$hdhrUrl" />
                </x-slot>
                <x-filament::input
                    type="text"
                    :value="$hdhrUrl"
                    readonly
                />
                <x-slot name="suffix">
                    hdhr
                </x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$record->name" body="HDHR URL" :text="$hdhrUrl" />
        </div>
    </div>
</x-dynamic-component>
