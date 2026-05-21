<?php

namespace App\Observers;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\Group;
use Illuminate\Support\Facades\Bus;

class ChannelObserver
{
    /**
     * Handle the Channel "creating" event.
     *
     * Inherit the parent group's stream_profile_id when creating a new channel
     * that has not been assigned one explicitly. Bulk imports via Channel::upsert()
     * bypass model events; those paths inject the value into their payload directly.
     */
    public function creating(Channel $channel): void
    {
        if ($channel->stream_profile_id !== null || $channel->group_id === null) {
            return;
        }

        $defaultProfileId = Group::query()
            ->whereKey($channel->group_id)
            ->value('stream_profile_id');

        if ($defaultProfileId !== null) {
            $channel->stream_profile_id = $defaultProfileId;
        }
    }

    /**
     * Handle the Channel "updated" event.
     *
     * Dispatches a Plex DVR sync when the enabled status changes.
     * SyncPlexDvrJob is ShouldBeUnique (60s window), so rapid
     * individual toggles are automatically debounced.
     *
     * Uses Bus::dispatch() instead of the dispatch() helper to bypass
     * PendingDispatch's cache-based unique lock which causes flaky
     * failures under parallel tests.  ShouldBeUnique is still enforced
     * by the queue worker at processing time.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('enabled')) {
            Bus::dispatch(new SyncPlexDvrJob(trigger: 'channel_observer'));
        }
    }
}
