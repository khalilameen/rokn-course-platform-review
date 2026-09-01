<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPortfolioService;
use App\Support\RoknPublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicPortfolioController extends Controller
{
    public function show(Request $request, string $slug, PublicPortfolioService $service)
    {
        $highlight = $request->query('certificate');
        if (is_string($highlight) && Str::isUuid($highlight)) {
            // Old QR codes included a mutable profile slug. Send every valid
            // UUID-shaped legacy link to the permanent credential route; that
            // route remains correct after the learner renames the profile.
            return redirect()
                ->away(RoknPublicUrl::certificate($highlight), 301)
                ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        $portfolio = $service->find($slug);
        abort_if(!$portfolio, 404);

        return response(view('portfolio.public', compact('portfolio')))
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
