<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class BiosecurityRuleController extends Controller
{
    /**
     * Landing page for the Biosecurity Rules module. Shows the two submodule
     * cards (Downtime Matrix / Downtime Stationary); each submodule's own
     * listing is loaded asynchronously only when its card/link is clicked,
     * never both at once.
     */
    public function index()
    {
        if (request()->ajax()) {
            return view('admin.biosecurity-rules._landing');
        }
        return view('admin.biosecurity-rules.index');
    }
}
