<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Path;
use App\Models\Classification;
use Illuminate\Http\Request;

class PathController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $paths = Path::query();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $paths->where(function($query) use ($search) {
                $query->where('title_ar', 'LIKE', "%{$search}%")
                      ->orWhere('title_en', 'LIKE', "%{$search}%");
            });
        }

        $paths = $paths->with('interests')->latest()->paginate(10);

        return view('admin.paths.index', compact('paths'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $interests = Classification::all();
        $courses = \App\Models\Course::orderBy('name_ar')->get();
        return view('admin.paths.create', compact('interests', 'courses'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'interest_ids' => 'nullable|array',
            'interest_ids.*' => 'exists:classifications,id',
        ]);

        $path = Path::create($request->only(['title_ar', 'title_en']));

        if ($request->has('interest_ids')) {
            $path->interests()->sync($request->interest_ids);
        }

        if ($request->has('course_ids')) {
            \App\Models\Course::whereIn('id', $request->course_ids)->update(['path_id' => $path->id]);
        }

        return redirect()->route('admin.paths.index')->with('success', 'تم إضافة المسار بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $path = Path::with(['interests', 'courses'])->findOrFail($id);
        $interests = Classification::all();
        $courses = \App\Models\Course::orderBy('name_ar')->get();
        return view('admin.paths.edit', compact('path', 'interests', 'courses'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $path = Path::findOrFail($id);

        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'interest_ids' => 'nullable|array',
            'interest_ids.*' => 'exists:classifications,id',
        ]);

        $path->update($request->only(['title_ar', 'title_en']));

        if ($request->has('interest_ids')) {
            $path->interests()->sync($request->interest_ids);
        } else {
            $path->interests()->detach();
        }

        // Dissociate courses no longer in the list
        \App\Models\Course::where('path_id', $path->id)->whereNotIn('id', $request->course_ids ?? [])->update(['path_id' => null]);
        // Associate newly added courses
        if ($request->has('course_ids')) {
            \App\Models\Course::whereIn('id', $request->course_ids)->update(['path_id' => $path->id]);
        }

        return redirect()->route('admin.paths.index')->with('success', 'تم تعديل المسار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $path = Path::findOrFail($id);
        $path->delete();

        return redirect()->route('admin.paths.index')->with('success', 'تم حذف المسار بنجاح');
    }
}
