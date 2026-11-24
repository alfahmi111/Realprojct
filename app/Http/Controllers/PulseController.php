<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/**
 * A small heartbeat for the header (owner request 2026-11-24): how many tasks wait in the office inbox and how many
 * in-app notifications are unread. The header polls it and plays a short sound when either number grows, unless the
 * user muted it with the speaker button (the same habit as the owner's previous system).
 */
class PulseController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $tasks = 0;
        if ($user->role->portal() === 'manager' && \App\Filament\Manager\Pages\InboxPage::canAccess()) {
            try {
                $tasks = array_sum(app(\App\Dashboards\ManagerInbox::class)->for($user)['counts']);
            } catch (\Throwable) {
                $tasks = 0;
            }
        }
        $unread = DB::table('notifications_outbox')->where('recipient_type', 'user')->where('recipient_id', $user->id)
            ->where('channel', 'in_app')->whereNull('read_at')->count();
        return response()->json(['tasks' => $tasks, 'unread' => $unread])->header('Cache-Control', 'no-store');
    }
}
