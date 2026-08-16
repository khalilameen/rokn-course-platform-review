<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeRequest;
use App\Models\Grade;
use App\Models\Setting;
use App\Models\DesignSetting;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * عرض قائمة المراحل الدراسية
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $grades = Grade::ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.index', compact('grades', 'designSettings'));
    }

    /**
     * عرض نموذج إنشاء مرحلة دراسية جديدة
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.create', compact('enableEnglish', 'designSettings'));
    }

    /**
     * حفظ مرحلة دراسية جديدة
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(GradeRequest $request)
    {
        $grade = Grade::create($request->validated());

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم إضافة المرحلة الدراسية بنجاح');
    }

    /**
     * عرض نموذج تعديل مرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function edit(Grade $grade)
    {
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.edit', compact('grade', 'enableEnglish', 'designSettings'));
    }

    /**
     * تحديث مرحلة دراسية
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function update(GradeRequest $request, Grade $grade)
    {
        $grade->update($request->validated());

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم تحديث المرحلة الدراسية بنجاح');
    }

    /**
     * حذف مرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function destroy(Grade $grade)
    {
        // Check if grade has associated courses
        if ($grade->courses()->count() > 0) {
            return redirect()->route('admin.grades.index')
                ->with('error', 'لا يمكن حذف المرحلة الدراسية لوجود كورسات مرتبطة بها');
        }

        $grade->delete();

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم حذف المرحلة الدراسية بنجاح');
    }

    /**
     * عرض الكورسات المرتبطة بمرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function courses(Grade $grade)
    {
        $courses = $grade->courses()->with('category')->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.courses', compact('grade', 'courses', 'designSettings'));
    }
}
