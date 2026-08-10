<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;

class TestClientController extends Controller
{
    public function index()
    {
        // For testing, grab ycookies.test specifically, or the first active domain
        $domain = Domain::where('name', 'ycookies.test')->first() ?? Domain::where('is_active', true)->first();

        $siteId = $domain ? $domain->site_id : 'missing-site-id';

        return view('test-client', compact('siteId'));
    }
}
