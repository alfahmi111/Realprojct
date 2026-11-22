<?php

namespace App\Http\Controllers\Api;

use App\Portals\TechnicianApp;
use Illuminate\Http\Request;

/** JSON endpoints used by the technician interface and its offline queue (REQ-0313, REQ-0495). */
class TechnicianController
{
    private function tech(Request $r)
    {
        $u = $r->user();
        abort_unless($u && $u->role->value === 'technician', 403);
        return $u;
    }

    public function workOrders(Request $r, TechnicianApp $app)
    {
        return response()->json($app->assigned($this->tech($r)));
    }

    public function scan(Request $r, string $qr, TechnicianApp $app)
    {
        return response()->json($app->scan($this->tech($r), $qr));
    }

    public function sync(Request $r, TechnicianApp $app)
    {
        $data = $r->validate(['ops' => ['required', 'array', 'max:200'], 'ops.*.id' => ['required', 'uuid'], 'ops.*.op' => ['required', 'string']]);
        return response()->json($app->sync($this->tech($r), $r->input('ops')));
    }
}
