<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\Request;

class ConsentHubController extends Controller
{
    /**
     * Serve the central consent hub iframe for cross-domain syncing.
     */
    public function serve(Request $request, $site_id)
    {
        $domain = Domain::with('group.domains')
            ->where('site_id', $site_id)
            ->where('is_active', true)
            ->first();

        if (!$domain || !$domain->cross_domain_enabled) {
            return response('Unauthorized. Cross-domain consent is heavily restricted.', 403);
        }

        // Cross-domain consent is only relevant if there's a group with multiple domains
        $allowedOrigins = [];
        $groupId = $domain->group_id;

        if ($domain->group && $domain->group->domains) {
            foreach ($domain->group->domains as $sibling) {
                if ($sibling->is_active) {
                    $allowedOrigins[] = 'http://' . $sibling->name;
                    $allowedOrigins[] = 'https://' . $sibling->name;
                    // Support local testing ports if necessary, but standard is just protocol+host
                }
            }
        }

        // Fallback: at least allow the requested domain
        if (empty($allowedOrigins)) {
            $allowedOrigins[] = 'http://' . $domain->name;
            $allowedOrigins[] = 'https://' . $domain->name;
        }

        $html = view('api.consent-hub', [
            'allowedOrigins' => $allowedOrigins,
            'groupId' => $groupId ?? $domain->id,
        ])->render();

        return response($html)->header('Content-Type', 'text/html');
    }
}
