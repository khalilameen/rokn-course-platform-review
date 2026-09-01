<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

final class AppLinkFallbackController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('landing');
    }
}
