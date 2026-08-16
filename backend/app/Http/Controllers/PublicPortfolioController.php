<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPortfolioService;
use Illuminate\Http\Request;

class PublicPortfolioController extends Controller
{
    public function show(Request $request, string $slug, PublicPortfolioService $service)
    {
        $portfolio = $service->find($slug, $request->query('certificate'));
        abort_if(!$portfolio, 404);

        return view('portfolio.public', compact('portfolio'));
    }
}
