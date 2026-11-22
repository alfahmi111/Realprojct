<?php

namespace App\Http\Controllers;

use App\Migration\DataExport;
use Illuminate\Http\Request;

/** One-click full export for the subscriber's manager — free, no support request (REQ-0498). */
class DataExportController
{
    public function __invoke(Request $request, DataExport $export)
    {
        abort_unless($request->user()?->hasPermission('settings.subscriber'), 403);
        return response()->download($export->export())->deleteFileAfterSend();
    }
}
