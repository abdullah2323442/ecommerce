<?php

namespace App\Http\Controllers;

use App\Services\Sales\OrderHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** One list of everything the buyer has ordered, from every part of the site. */
class OrderHistoryController extends Controller
{
    public function index(Request $request, OrderHistory $history): Response
    {
        return Inertia::render('history', ['orders' => array_map(fn (array $r) => in_array($r['kind'], ['medicine', 'custom'], true) ? array_merge($r, ['title' => __($r['title'])]) : $r, $history->for($request->user()))]);
    }
}
