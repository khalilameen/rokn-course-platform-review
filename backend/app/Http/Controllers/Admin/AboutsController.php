<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\About;
use Illuminate\Http\Request;

class AboutsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function aboutForDisplay(): About
    {
        return About::query()->first() ?? new About();
    }

    private function aboutForUpdate(): About
    {
        return About::query()->firstOrCreate([]);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function privacy()
    {
        return view('admin.abouts.privacy', ['about' => $this->aboutForDisplay()]);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function policy()
    {
        return view('admin.abouts.policy', ['about' => $this->aboutForDisplay()]);
    }
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        $this->aboutForUpdate()->update($request->input());

        return redirect()->back();
    }
}
