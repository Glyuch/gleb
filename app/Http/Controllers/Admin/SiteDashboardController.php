<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildSiteReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SiteDashboardController extends Controller
{
    public function index(BuildSiteReport $report): View
    {
        return view('admin.dashboards.site', ['report' => $report()]);
    }
}
