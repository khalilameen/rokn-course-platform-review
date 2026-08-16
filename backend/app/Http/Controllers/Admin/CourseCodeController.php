<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseCodeRequest;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\DesignSetting;
use App\Models\Lesson;
use App\Services\ArabicPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseCodeController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = CourseCode::with(['course', 'lesson', 'usages'])
            ->when($request->code, function($q, $code) {
                return $q->where('code', 'like', "%{$code}%");
            })
            ->when($request->name, function($q, $name) {
                return $q->where('name', 'like', "%{$name}%");
            })
            ->when($request->type, function($q, $type) {
                return $q->where('type', $type);
            })
            ->when($request->course_id, function($q, $courseId) {
                return $q->where('course_id', $courseId);
            })
            ->when($request->lesson_id, function($q, $lessonId) {
                return $q->where('lesson_id', $lessonId);
            })
            ->when($request->start_date, function($q, $startDate) {
                return $q->whereDate('start_date', $startDate);
            })
            ->when($request->expiry_date, function($q, $expiryDate) {
                return $q->whereDate('expiry_date', $expiryDate);
            })
            ->when($request->status, function($q, $status) {
                if ($status === 'active') {
                    return $q->where('is_active', true);
                } elseif ($status === 'inactive') {
                    return $q->where('is_active', false);
                } elseif ($status === 'expired') {
                    return $q->where('expiry_date', '<', now());
                } elseif ($status === 'not_yet_active') {
                    return $q->where('start_date', '>', now());
                }
                return $q;
            });

        // Efficiently update is_active status based on expiry and usage
        // This only runs once per page load and uses a single query
        /*
        DB::statement("
            UPDATE course_codes
            SET is_active = CASE
                WHEN expiry_date IS NOT NULL AND expiry_date < NOW() THEN 0
                WHEN start_date IS NOT NULL AND start_date > NOW() THEN 0
                WHEN used_count >= max_uses THEN 0
                ELSE 1
            END
            WHERE (
                (expiry_date IS NOT NULL AND expiry_date < NOW() AND is_active = 1) OR
                (start_date IS NOT NULL AND start_date > NOW() AND is_active = 1) OR
                (used_count >= max_uses AND is_active = 1) OR
                (
                    (expiry_date IS NULL OR expiry_date >= NOW()) AND
                    (start_date IS NULL OR start_date <= NOW()) AND
                    used_count < max_uses AND
                    is_active = 0
                )
            )
        ");
        */

        $courseCodes = $query->orderBy('created_at', 'desc')->paginate(10);
        $courses = Course::all();
        $lessons = Lesson::all();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.index', compact('courseCodes', 'courses', 'lessons', 'designSettings'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = Course::all();
        $lessons = Lesson::all();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.create', compact('courses', 'lessons', 'designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\Admin\CourseCodeRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CourseCodeRequest $request)
    {
        try {
            DB::beginTransaction();

            $numberOfCodes = $request->input('number_of_codes', 1);
            $createdCodes = [];

            for ($i = 0; $i < $numberOfCodes; $i++) {
                $codeData = [
                    'code' => CourseCode::generateUniqueCode(),
                    'name' => $request->input('name'),
                    'type' => $request->input('type'),
                    'start_date' => $request->input('start_date'),
                    'expiry_date' => $request->input('expiry_date'),
                    'max_uses' => $request->input('max_uses'),
                    'description' => $request->input('description'),
                    'allowed_email_domains' => $this->emailDomains($request->input('allowed_email_domains')),
                    'is_grant' => $request->boolean('is_grant'),
                ];

                // Set content based on type
                switch ($request->input('type')) {
                    case 'course':
                        $codeData['course_id'] = $request->input('course_id');
                        break;
                    case 'lesson':
                        $codeData['lesson_id'] = $request->input('lesson_id');
                        break;
                    case 'multiple_lessons':
                        $codeData['course_id'] = $request->input('course_id');
                        $codeData['lesson_ids'] = $request->input('lesson_ids');
                        break;
                }

                $createdCodes[] = CourseCode::create($codeData);
            }

            DB::commit();

            $message = $numberOfCodes > 1
                ? "تم إنشاء {$numberOfCodes} أكواد بنجاح"
                : "تم إنشاء الكود بنجاح";

            return redirect()->route('admin.course-codes.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'حدث خطأ أثناء إنشاء الأكواد: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function show(CourseCode $courseCode)
    {
        $courseCode->load(['course', 'lesson', 'usages.user']);
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.show', compact('courseCode', 'designSettings'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function edit(CourseCode $courseCode)
    {
        $courses = Course::all();
        $lessons = Lesson::all();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.edit', compact('courseCode', 'courses', 'lessons', 'designSettings'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\Admin\CourseCodeRequest  $request
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function update(CourseCodeRequest $request, CourseCode $courseCode)
    {
        try {
            $data = $request->validated();

            // Remove fields that shouldn't be updated
            unset($data['number_of_codes']);
            $data['allowed_email_domains'] = $this->emailDomains(
                $request->input('allowed_email_domains')
            );
            $data['is_grant'] = $request->boolean('is_grant');

            $courseCode->update($data);

            return redirect()->route('admin.course-codes.index')
                ->with('success', 'تم تحديث الكود بنجاح');

        } catch (\Exception $e) {
            return back()->with('error', 'حدث خطأ أثناء تحديث الكود: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function destroy(CourseCode $courseCode)
    {
        try {
            $courseCode->delete();
            return redirect()->route('admin.course-codes.index')
                ->with('success', 'تم حذف الكود بنجاح');
        } catch (\Exception $e) {
            return back()->with('error', 'حدث خطأ أثناء حذف الكود: ' . $e->getMessage());
        }
    }

    private function emailDomains(?string $value): ?array
    {
        $domains = collect(preg_split('/[,\r\n]+/', (string) $value))
            ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $domains ?: null;
    }

    /**
     * Bulk actions for course codes
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:delete,activate,deactivate',
            'selected_codes' => 'required|array|min:1',
            'selected_codes.*' => 'exists:course_codes,id'
        ]);

        try {
            $action = $request->input('action');
            $selectedCodes = $request->input('selected_codes');

            switch ($action) {
                case 'delete':
                    CourseCode::whereIn('id', $selectedCodes)->delete();
                    $message = 'تم حذف الأكواد المحددة بنجاح';
                    break;

                case 'activate':
                    CourseCode::whereIn('id', $selectedCodes)->update(['is_active' => true]);
                    $message = 'تم تفعيل الأكواد المحددة بنجاح';
                    break;

                case 'deactivate':
                    CourseCode::whereIn('id', $selectedCodes)->update(['is_active' => false]);
                    $message = 'تم إلغاء تفعيل الأكواد المحددة بنجاح';
                    break;
            }

            return redirect()->route('admin.course-codes.index')
                ->with('success', $message);

        } catch (\Exception $e) {
            return back()->with('error', 'حدث خطأ أثناء تنفيذ العملية: ' . $e->getMessage());
        }
    }

    /**
     * Get lessons for a specific course via AJAX
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLessons(Request $request)
    {
        $courseId = $request->input('course_id');

        if (!$courseId) {
            return response()->json([]);
        }

        try {
            $lessons = Lesson::where('list_id', $courseId)
                ->orderBy('priority')
                ->get(['id', 'title']);

            \Log::info('Lessons loaded for course ' . $courseId . ': ' . $lessons->count() . ' lessons found');

            return response()->json($lessons);
        } catch (\Exception $e) {
            \Log::error('Error loading lessons for course ' . $courseId . ': ' . $e->getMessage());
            return response()->json(['error' => 'حدث خطأ أثناء تحميل الدروس'], 500);
        }
    }

    /**
     * Export codes to CSV
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $query = CourseCode::with(['course', 'lesson', 'usages'])
            ->when($request->code, function($q, $code) {
                return $q->where('code', 'like', "%{$code}%");
            })
            ->when($request->type, function($q, $type) {
                return $q->where('type', $type);
            })
            ->when($request->status, function($q, $status) {
                if ($status === 'active') {
                    return $q->where('is_active', true);
                } elseif ($status === 'inactive') {
                    return $q->where('is_active', false);
                }
                return $q;
            });

        $courseCodes = $query->get();

        $filename = 'course_codes_' . date('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($courseCodes) {
            $file = fopen('php://output', 'w');

            // Add BOM for Arabic text
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            fputcsv($file, [
                'الكود',
                'الاسم',
                'النوع',
                'الدورة/الدرس',
                'تاريخ البداية',
                'تاريخ الانتهاء',
                'الاستخدامات',
                'الحد الأقصى',
                'منحة مؤسسية',
                'نطاقات البريد',
                'الحالة',
                'تاريخ الإنشاء'
            ]);

            // Data
            foreach ($courseCodes as $code) {
                fputcsv($file, [
                    $code->code,
                    $code->name,
                    $this->getTypeName($code->type),
                    $code->target_content_name,
                    $code->start_date ? $code->start_date->format('Y-m-d') : '',
                    $code->expiry_date ? $code->expiry_date->format('Y-m-d') : '',
                    $code->used_count,
                    $code->max_uses,
                    $code->isInstitutionalGrant() ? 'نعم' : 'لا',
                    implode(', ', $code->allowed_email_domains ?? []),
                    $code->is_active ? 'مفعل' : 'معطل',
                    $code->created_at->format('Y-m-d H:i:s')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export course codes to PDF for printing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportToPdf(Request $request, ArabicPdfService $pdfService)
    {
        try {
            set_time_limit(300);

            $courseCodes = CourseCode::with(['course', 'lesson'])->get();

            if ($courseCodes->isEmpty()) {
                return back()->with('error', 'لا توجد أكواد للتصدير');
            }

            // Log the count for debugging
            \Log::info('PDF Export - Total codes fetched: ' . $courseCodes->count());

            // Transform the data for PDF
            $transformedCodes = collect();
            foreach ($courseCodes as $code) {
                // Get the target content name based on type
                $targetContentName = 'غير محدد';
                if ($code->type === 'course' && $code->course) {
                    $targetContentName = $code->course->name_ar ?? 'غير محدد';
                } elseif ($code->type === 'lesson' && $code->lesson) {
                    $targetContentName = $code->lesson->name_ar ?? 'غير محدد';
                } elseif ($code->type === 'multiple_lessons') {
                    $targetContentName = 'دروس متعددة';
                }

                $transformedCodes->push((object) [
                    'name' => $code->name ?? 'غير محدد',
                    'target_content_name' => $targetContentName,
                    'code' => $code->code ?? 'غير محدد',
                    'type' => $code->type ?? 'course',
                    'max_uses' => $code->max_uses ?? 0,
                    'is_grant' => $code->isInstitutionalGrant(),
                    'allowed_email_domains' => $code->allowed_email_domains ?? [],
                ]);
            }

            \Log::info('PDF Export - Transformed codes count: ' . $transformedCodes->count());

            $designSettings = $this->getDesignSettings();
            
            $data = [
                'course_codes' => $transformedCodes,
                'platform_name' => $designSettings->name_ar ?? 'منصة تعليمية',
                'export_date' => now()->format('Y-m-d H:i:s'),
                'total_codes' => $transformedCodes->count()
            ];

            // Generate PDF using LaravelPdf package (mPDF wrapper)
            $pdf = \PDF::loadView('admin.course-codes.pdf', $data);
            
            // Set PDF metadata dynamically from settings
            $pdf->setAuthor($designSettings->name_ar ?? 'منصة تعليمية');
            $pdf->setCreator($designSettings->name_ar ?? 'منصة تعليمية');

            // Generate filename
            $filename = 'Course_Codes_' . date('Y-m-d_H-i-s') . '.pdf';

            // Force download the PDF
            return $pdf->download($filename);

                  } catch (\Exception $e) {
              return back()->with('error', 'خطأ في تصدير الأكواد: ' . $e->getMessage().' '.$e->getLine().' '.$e->getFile());
          }
    }

    /**
     * Get Arabic name for code type
     */
    private function getTypeName($type)
    {
        $types = [
            'course' => 'دورة',
            'lesson' => 'درس',
            'multiple_lessons' => 'دروس متعددة'
        ];

        return $types[$type] ?? $type;
    }
}

