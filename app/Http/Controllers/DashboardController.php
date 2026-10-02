<?php

namespace App\Http\Controllers;

use App\Support\DashboardMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): View
    {
        $input = $request->validate(['period' => ['sometimes', 'string', Rule::in(['year', 'previous-year'])]]);

        return view('dashboard', $metrics->forUser($request->user(), $input['period'] ?? 'year'));
    }
}
