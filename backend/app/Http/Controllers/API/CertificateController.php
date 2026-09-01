<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Order;
use App\Models\Project;
use App\Services\CertificateEligibilityService;
use App\Services\CertificateService;
use App\Services\CourseChatAccessService;
use Illuminate\Http\JsonResponse;

final class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CertificateEligibilityService $eligibility
    ) {
    }

    /**
     * List all certificates for the authenticated user with course minimum details.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        $certificates = Certificate::where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->with('course')
            ->orderBy('generated_at', 'desc')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Certificate $certificate): bool =>
                $this->courseAccess->hasCertificateAccess(
                    (int) $user->id,
                    (int) $certificate->course_id
                )
            )
            ->values();

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'تم تحميل الشهادات',
            'data'    => CertificateResource::collection($certificates),
        ]);
    }

    /** Read an already-issued credential without producing side effects. */
    public function show($courseId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        $certificate = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->with('course')
            ->first();
        if (!$certificate) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'code' => 'certificate_not_issued',
                'message' => 'لم تصدر الشهادة بعد',
                'data' => null,
            ], 404);
        }
        if (($certificate->status ?? 'active') !== 'active') {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'certificate_revoked',
                'message' => 'هذه الشهادة ملغاة',
                'data' => null,
            ], 410);
        }
        if (!$this->courseAccess->hasCertificateAccess((int) $user->id, (int) $courseId)) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'certificate_access_revoked',
                'message' => 'هذه الشهادة غير متاحة',
                'data' => null,
            ], 410);
        }
        if (!$certificate->hasStoredArtifact()) {
            return response()->json([
                'status' => 202,
                'success' => true,
                'code' => 'certificate_generating',
                'message' => 'نجهّز شهادتك الآن',
                'data' => null,
            ], 202);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الشهادة',
            'data' => new CertificateResource($certificate),
        ]);
    }

    /** Issue a new certificate or recover its pending artifact. */
    public function issue($courseId): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status'  => 401,
                'success' => false,
                'message' => 'سجّل الدخول أولًا',
                'data' => null,
            ], 401);
        }

        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->with('course')
            ->first();

        if ($certificate) {
            if (($certificate->status ?? 'active') !== 'active') {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'certificate_revoked',
                    'message' => 'هذه الشهادة ملغاة',
                    'data' => null,
                ], 410);
            }
            if (!$this->courseAccess->hasCertificateAccess(
                (int) $user->id,
                (int) $courseId
            )) {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'certificate_access_revoked',
                    'message' => 'هذه الشهادة غير متاحة',
                    'data' => null,
                ], 410);
            }

            if (!$certificate->hasStoredArtifact()) {
                // A pending row remains retryable until generation succeeds.
                $recovered = $this->certificates->generate(
                    $user,
                    $certificate->course,
                    $certificate->project_id
                        ? Project::find($certificate->project_id)
                        : null,
                );
                if ($recovered && $recovered->image_path !== 'pending') {
                    $recovered->load('course');

                    return response()->json([
                        'status' => 200,
                        'success' => true,
                        'message' => 'تم تحميل الشهادة',
                        'data' => new CertificateResource($recovered),
                    ]);
                }

                return response()->json([
                    'status' => 202,
                    'success' => true,
                    'code' => 'certificate_generating',
                    'message' => 'نجهّز شهادتك الآن',
                    'data' => null,
                ], 202);
            }

            return response()->json([
                'status'  => 200,
                'success' => true,
                'message' => 'تم تحميل الشهادة',
                'data'    => new CertificateResource($certificate),
            ]);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return response()->json([
                'status'  => 404,
                'success' => false,
                'message' => 'الكورس غير متاح',
                'data' => null,
            ], 404);
        }

        if (!$course->isPublishedForLearning() || $course->isNestedCourse()) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'الكورس غير متاح',
                'data' => null,
            ], 404);
        }

        $enrollment = $this->courseAccess->activeEnrollmentFor(
            (int) $user->id,
            (int) $courseId
        );

        if (!$enrollment) {
            return response()->json([
                'status'  => 403,
                'success' => false,
                'message' => 'هذا الكورس غير مضاف إلى حسابك',
                'data' => null,
            ], 403);
        }

        // Certificate access is checked independently from learning access.
        if (!$this->courseAccess->hasCertificateAccess(
            (int) $user->id,
            (int) $courseId
        )) {
            return response()->json([
                'status' => 402,
                'success' => false,
                'code' => 'certificate_upgrade_required',
                'message' => "المنحة تشمل الكورس والمشروعات\nالشهادة متاحة في الفئات المدفوعة",
                'data' => [
                    'learning_access' => true,
                    'certificate_available' => false,
                    'upgrade_endpoint' => "/api/v1/courses/{$courseId}/full-track-upgrade",
                ],
            ], 402);
        }

        // Do not issue a new certificate while the related order is under review.
        if ($enrollment->order_id && Order::query()
            ->whereKey($enrollment->order_id)
            ->where('user_id', $user->id)
            ->whereIn('financial_status', [
                Order::FINANCIAL_PARTIALLY_RECOVERED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ])
            ->where('unrecovered_coins', '>', 0)
            ->exists()) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'certificate_financial_review_required',
                'message' => "إصدار الشهادة متوقف مؤقتًا\nنعالج عملية الدفع المرتبطة بها",
                'data' => null,
            ], 409);
        }

        $eligibility = $this->eligibility->for($user, $course);
        if (!$eligibility['available']) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'certificate_' . $eligibility['reason'],
                'message' => "أكمل المطلوب أولًا\nثم اطلب الشهادة",
                'data' => null,
            ], 403);
        }

        $graduationProject = Project::where('is_graduation_project', true)
            ->whereHas('section', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            })
            ->first();

        $certificate = $this->certificates->generate($user, $course, $graduationProject);

        if (!$certificate) {
            return response()->json([
                'status'  => 500,
                'success' => false,
                'message' => "تعذّر إصدار الشهادة الآن\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }

        $certificate->load('course');

        return response()->json([
            'status'  => 200,
            'success' => true,
            'message' => 'تم إصدار الشهادة',
            'data'    => new CertificateResource($certificate),
        ]);
    }

}
