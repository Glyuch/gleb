<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Game\BuildGameResultsReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class GameResultsDashboardController extends Controller
{
    public function index(BuildGameResultsReport $report): View
    {
        return view('admin.dashboards.gameresults', ['report' => $report()]);
    }
}
