<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TeacherController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $teachers = User::query()
            ->where('role', 'teacher')
            ->with('photo')
            ->withCount('teachingCourses');

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $teachers->where(function($query) use ($search) {
                $query->where('name_ar', 'LIKE', "%{$search}%")
                      ->orWhere('name_en', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $teachers = $teachers->latest()->paginate(10);

        return view('admin.teachers.index', compact('teachers'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.teachers.create');
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
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
        ]);

        $teacher = User::create([
            'name_ar' => $request->string('name_ar')->trim(),
            'name_en' => $request->filled('name_en') ? $request->string('name_en')->trim() : null,
            'email' => strtolower($request->string('email')->trim()),
            'phone' => $request->string('phone')->trim(),
            'password' => Hash::make($request->password),
            'job_title' => $request->input('job_title'),
            'bio_ar' => $request->input('bio_ar'),
            'bio_en' => $request->input('bio_en'),
        ]);
        // Teacher accounts do not receive dashboard privileges.
        $teacher->forceFill(['role' => 'teacher', 'active' => $request->boolean('active')])->save();

        if ($request->hasFile('image')) {
            $teacher->storeImage($request->file('image'), 'users', 'featured');
        }

        return redirect()->route('admin.teachers.index')->with('success', 'تم إضافة المعلم بنجاح');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        $courses = $teacher->teachingCourses()->paginate(10);

        return view('admin.teachers.show', compact('teacher', 'courses'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        return view('admin.teachers.edit', compact('teacher'));
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
        $teacher = User::where('role', 'teacher')->findOrFail($id);

        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$teacher->id,
            'phone' => 'required|string|max:20|unique:users,phone,'.$teacher->id,
            'password' => 'nullable|string|min:6|confirmed',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
        ]);

        $userData = $request->only(['name_ar', 'name_en', 'email', 'phone', 'job_title', 'bio_ar', 'bio_en']);
        $userData['email'] = strtolower(trim((string) $userData['email']));
        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $teacher->update($userData);
        $teacher->forceFill(['active' => $request->boolean('active')])->save();

        if ($request->hasFile('image')) {
            $teacher->replaceImage($request->file('image'), 'users', 'featured');
        }

        return redirect()->route('admin.teachers.index')->with('success', 'تم تعديل بيانات المعلم بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        $teacher->delete();

        return redirect()->route('admin.teachers.index')->with('success', 'تم حذف المعلم بنجاح');
    }

    /**
     * Toggle active status.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deactive($id)
    {
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        $teacher->forceFill(['active' => !$teacher->active])->save();
        return redirect()->back()->with('success', $teacher->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }
}
