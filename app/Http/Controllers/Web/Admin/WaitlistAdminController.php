<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Waitlist\WaitlistStats;
use Illuminate\Contracts\View\View;

class WaitlistAdminController extends Controller
{
    public function __construct(private readonly WaitlistStats $stats) {}

    /**
     * Back-office waitlist dashboard. Protected by auth + role:admin (see routes).
     * Aggregates only — never lists individual emails.
     */
    public function index(): View
    {
        return view('admin.waitlist', ['summary' => $this->stats->adminSummary()]);
    }
}
