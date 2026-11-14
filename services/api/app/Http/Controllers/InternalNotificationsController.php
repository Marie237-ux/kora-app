<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InternalNotificationsController extends OperationalController
{
    private function visible(Request $request)
    {
        return DB::table('operational_notifications as n')
            ->join('operational_alerts as a', 'a.id', '=', 'n.alert_id')
            ->leftJoin('operational_notification_reads as r', function ($join) use ($request) {
                $join->on('r.notification_id', '=', 'n.id')->where('r.user_id', $request->user()->id);
            })
            ->whereIn('n.audience_permission', $request->user()->permissions());
    }

    public function index(Request $request)
    {
        $data = $request->validate(['unread' => ['nullable', 'boolean']]);
        $q = $this->visible($request)
            ->select(
                'n.id',
                'n.alert_id',
                'n.kind',
                'n.created_at',
                'r.read_at',
                'a.state as alert_state',
                'a.severity as alert_severity',
            )
            ->orderByDesc('n.created_at')
            ->orderBy('n.id');
        $unread = (clone $q)->whereNull('r.read_at')->count();
        if (! empty($data['unread'])) {
            $q->whereNull('r.read_at');
        }

        return [
            ...$this->page(
                $q,
                $request,
                fn ($r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'title' => app()->getLocale() === 'fr'
                            ? 'Une échéance fournisseur nécessite votre attention.'
                            : 'A supplier payable needs your attention.',
                    'alert_id' => $r->alert_id,
                    'alert_state' => $r->alert_state,
                    'alert_severity' => $r->alert_severity,
                    'resource_url' => '/api/v1/operations/payables/alerts/'.$r->alert_id,
                    'read_at' => Records::iso($r->read_at),
                    'created_at' => Records::iso($r->created_at),
                ],
            ),
            'unread_total' => $unread,
        ];
    }

    public function markRead(Request $request, string $id)
    {
        return $this->command($request, 'internal_notification.read:'.$id, [], function () use ($request, $id) {
            $row = Records::row('operational_notifications', $id);
            abort_unless(in_array($row->audience_permission, $request->user()->permissions(), true), 404);
            DB::table('operational_notification_reads')->insertOrIgnore([
                'notification_id' => $id,
                'user_id' => $request->user()->id,
                'read_at' => now(),
            ]);
            $read = DB::table('operational_notification_reads')
                ->where('notification_id', $id)
                ->where('user_id', $request->user()->id)
                ->value('read_at');

            return ['id' => $id, 'read_at' => Records::iso($read)];
        });
    }
}
