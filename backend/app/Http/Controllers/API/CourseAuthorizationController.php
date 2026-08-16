<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Bill;
use App\Models\StudentSectionProgress;
use App\Models\WatchingLog;
use App\Models\PaymentMethod;
use App\Services\CourseChatAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseAuthorizationController extends Controller
{
    public function __construct(
        private readonly CourseChatAccessService $courseAccess
    ) {
    }

    /**
     * Get active payment methods.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentMethods(): JsonResponse
    {
        $paymentMethods = PaymentMethod::where('is_active', true)->get()
            ->map(function ($method) {
                return [
                    'id' => $method->id,
                    'name' => $method->name,
                    'account_details' => $method->account_details,
                    'description' => $method->description,
                ];
            });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Payment methods retrieved successfully',
            'data' => $paymentMethods
        ]);
    }

    /**
     * Get user's course enrollments.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myEnrollments(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        $enrollments = CourseEnrollment::with(['course.sections', 'order'])
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $allSectionIds = $enrollments->flatMap(function ($enrollment) {
            return $enrollment->course->sections->pluck('id');
        })->unique()->values()->all();

        $progressRows = collect();
        if (!empty($allSectionIds)) {
            $progressRows = StudentSectionProgress::where('user_id', $user->id)
                ->whereIn('course_section_id', $allSectionIds)
                ->get(['course_section_id', 'is_completed', 'created_at', 'updated_at']);
        }

        $completedSectionIds = $progressRows
            ->where('is_completed', true)
            ->pluck('course_section_id')
            ->unique();

        // One compact query supplies resume position and real "started" state.
        // It remains separate from academic completion, which is still governed
        // exclusively by StudentSectionProgress and project rules.
        $latestWatchByCourse = WatchingLog::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $enrollments->pluck('course_id'))
            ->orderByRaw('COALESCE(watched_at, updated_at) DESC')
            ->get([
                'id', 'course_id', 'lesson_id', 'course_section_id',
                'position_seconds', 'duration_seconds', 'watched_at', 'updated_at',
            ])
            ->unique('course_id')
            ->keyBy('course_id');

        // Pre-load certificate course IDs in a single query (avoids N+1)
        $certificateCourseIds = \App\Models\Certificate::where('user_id', $user->id)
            ->whereIn('course_id', $enrollments->pluck('course_id'))
            ->pluck('course_id')
            ->flip();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Enrollments retrieved successfully',
            'data' => $enrollments->map(function ($enrollment) use (
                $completedSectionIds,
                $certificateCourseIds,
                $progressRows,
                $latestWatchByCourse
            ) {
                $sectionIds = $enrollment->course->sections->pluck('id');
                $totalSections = $sectionIds->count();
                $completedSections = $sectionIds->intersect($completedSectionIds)->count();
                $progressPercentage = $totalSections > 0
                    ? round(($completedSections / $totalSections) * 100, 2)
                    : 0.0;

                $courseProgress = $progressRows->whereIn('course_section_id', $sectionIds);
                $latestWatch = $latestWatchByCourse->get($enrollment->course_id);
                $isCompleted = $totalSections > 0 && $completedSections >= $totalSections;
                $hasStarted = $courseProgress->isNotEmpty() || $latestWatch !== null;
                $learningState = $isCompleted
                    ? 'completed'
                    : ($hasStarted ? 'in_progress' : 'not_started');

                $resumeSection = null;
                if ($latestWatch?->course_section_id
                    && !$completedSectionIds->contains($latestWatch->course_section_id)) {
                    $resumeSection = $enrollment->course->sections
                        ->firstWhere('id', $latestWatch->course_section_id);
                }

                if (!$resumeSection && !$isCompleted) {
                    $resumeSection = $enrollment->course->sections->first(
                        function ($section) use ($completedSectionIds) {
                            return !$completedSectionIds->contains($section->id);
                        }
                    );
                }

                $continueTarget = null;
                if ($resumeSection) {
                    $sectionType = $resumeSection->getSectionType();
                    $reelNumber = $sectionType === 'lesson'
                        ? $enrollment->course->sections
                            ->takeUntil(fn ($section) => $section->id === $resumeSection->id)
                            ->filter(fn ($section) => $section->getSectionType() === 'lesson')
                            ->count() + 1
                        : null;

                    $continueTarget = [
                        'course_section_id' => $resumeSection->id,
                        'type' => $sectionType,
                        'lesson_id' => $sectionType === 'lesson' ? $resumeSection->sectionable_id : null,
                        'project_id' => $sectionType === 'project' ? $resumeSection->sectionable_id : null,
                        'module_id' => $resumeSection->module_id,
                        'order' => $resumeSection->order,
                        'reel_number' => $reelNumber,
                        'position_seconds' => $latestWatch?->course_section_id === $resumeSection->id
                            ? $latestWatch->position_seconds
                            : 0,
                    ];
                }

                $latestProgressAt = $courseProgress->sortByDesc('updated_at')->first()?->updated_at;
                $latestWatchAt = $latestWatch?->watched_at ?? $latestWatch?->updated_at;
                $lastActivityAt = collect([
                    $latestProgressAt,
                    $latestWatchAt,
                    $enrollment->enrolled_at,
                ])->filter()->sortByDesc(fn ($date) => $date->getTimestamp())->first();

                return [
                    'enrollment_id' => $enrollment->id,
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->name_ar,
                    'title_en' => $enrollment->course->name_en,
                    'image' => $enrollment->course->image,
                    'price' => $enrollment->course->price,
                    'progress_percentage' => $progressPercentage,
                    'learning_state' => $learningState,
                    'is_started' => $hasStarted,
                    'is_completed' => $isCompleted,
                    'completed_sections_count' => $completedSections,
                    'total_sections_count' => $totalSections,
                    'last_activity_at' => $lastActivityAt,
                    'continue_target' => $continueTarget,
                    'course' => [
                        'id' => $enrollment->course->id,
                        'title' => $enrollment->course->name_ar,
                        'title_en' => $enrollment->course->name_en,
                        'image' => $enrollment->course->image,
                        'description' => $enrollment->course->description,
                        'created_at' => $enrollment->course->created_at,
                        'price' => $enrollment->course->price,
                        'progress_percentage' => $progressPercentage,
                        'learning_state' => $learningState,
                        'completed_sections_count' => $completedSections,
                        'total_sections_count' => $totalSections,
                    ],
                    'enrolled_at' => $enrollment->enrolled_at,
                    'expires_at' => $enrollment->expires_at,
                    'is_active' => $enrollment->isActive(),
                    'access_granted_at' => $enrollment->access_granted_at,
                    'has_certificate' => isset($certificateCourseIds[$enrollment->course_id]),
                ];
            })
        ]);
    }

    /**
     * Check if user has access to a course.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAccess(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id'
        ]);

        $user = auth('api')->user();
        $courseId = $request->course_id;

        // Check direct enrollment
        $enrollment = CourseEnrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();

        $hasAccess = $enrollment && $enrollment->isActive();

        // If no direct access, check parent course access
        if (!$hasAccess) {
            $parentAccess = $this->checkParentCourseAccess(
                (int) $user->id,
                (int) $courseId
            );
            if ($parentAccess['has_access']) {
                $hasAccess = true;
                $enrollment = $parentAccess['enrollment'];
            }
        }

        // Keep this legacy endpoint on the same entitlement contract as the
        // course-details resource. Direct enrollment rows can also represent
        // course-code/institutional grants, so they must not be labelled paid.
        $entitlement = $this->courseAccess->entitlementFor(
            (int) $user->id,
            (int) $courseId
        );
        $hasAccess = $entitlement['has_learning_access'];

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Access check completed',
            'data' => [
                'has_access' => $hasAccess,
                'access_type' => $entitlement['access_type'],
                'chat_available' => $entitlement['chat_available'],
                'certificate_available' => $entitlement['certificate_available'],
                'enrollment' => $hasAccess ? [
                    'id' => $enrollment->id,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'expires_at' => $enrollment->expires_at,
                    'access_granted_at' => $enrollment->access_granted_at
                ] : null
            ]
        ]);
    }

    /**
     * Check if user has access to a course through parent course enrollment.
     *
     * @param int $userId The user ID
     * @param int $courseId The course ID to check access for
     * @return array Array containing access status and enrollment info
     */
    private function checkParentCourseAccess(int $userId, int $courseId): array
    {
        // Find all parent courses that contain this course as a section
        $parentCourseIds = CourseSection::where('sectionable_type', 'App\Models\Course')
            ->where('sectionable_id', $courseId)
            ->pluck('course_id')
            ->toArray();

        // If no parent courses found, return false
        if (empty($parentCourseIds)) {
            return ['has_access' => false, 'enrollment' => null];
        }

        // Check if user is enrolled in any of the parent courses
        $parentEnrollment = CourseEnrollment::where('user_id', $userId)
            ->whereIn('course_id', $parentCourseIds)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$parentEnrollment || !$parentEnrollment->isActive()) {
            return ['has_access' => false, 'enrollment' => null];
        }

        return [
            'has_access' => true,
            'enrollment' => $parentEnrollment
        ];
    }



    /**
     * Get user's course purchase orders.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myCourseOrders(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $user = auth('api')->user();

            $orders = Order::with(['course', 'courseCode', 'coupon', 'approvedBy', 'bill'])
                ->where('user_id', $user->id)
                ->whereNotNull('course_id') // Only course orders
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Course orders retrieved successfully',
                'data' => [
                    'orders' => $orders->map(function ($order) {
                        return [
                            'id' => $order->id,
                            'course' => [
                                'id' => $order->course->id,
                                'title' => $order->course->name_ar,
                                'title_en' => $order->course->name_en,
                                'image' => $order->course->image,
                                'price' => $order->course->price
                            ],
                            'payment_method' => $order->payment_method,
                            'payment_method_display' => $this->getPaymentMethodDisplay($order->payment_method),
                            'amount' => $order->amount,
                            'discount_amount' => $order->discount_amount,
                            'final_amount' => $order->final_amount,
                            'coin_allocation' => $order->total_coins !== null ? [
                                'total_coins' => (int) $order->total_coins,
                                'paid_coins' => (int) $order->paid_coins,
                                'reward_coins' => (int) $order->reward_coins,
                                'spend_policy' => 'reward_first_then_paid',
                            ] : null,
                            'status' => $order->status,
                            'status_display' => $this->getOrderStatusDisplay($order->status),
                            'course_code' => $order->courseCode ? [
                                'code' => $order->courseCode->code,
                                'type' => $order->courseCode->type
                            ] : null,
                            'coupon' => $order->coupon ? [
                                'code' => $order->coupon->code,
                                'discount_type' => $order->coupon->discount_type,
                                'discount_value' => $order->coupon->discount_value
                            ] : null,
                            'payment_screenshot' => $order->payment_screenshot,
                            'notes' => $order->notes,
                            'approved_at' => $order->approved_at,
                            'approved_by' => $order->approvedBy ? [
                                'id' => $order->approvedBy->id,
                                'name' => $order->approvedBy->name
                            ] : null,
                            'created_at' => $order->created_at,
                            'updated_at' => $order->updated_at
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                        'from' => $orders->firstItem(),
                        'to' => $orders->lastItem()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to retrieve course orders',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get user's billing history.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function myBills(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $user = auth('api')->user();

            $bills = Bill::with(['order.course', 'order.courseCode', 'order.coupon'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Billing history retrieved successfully',
                'data' => [
                    'bills' => $bills->map(function ($bill) {
                        return [
                            'id' => $bill->id,
                            'bill_number' => $bill->bill_number,
                            'order' => $bill->order ? [
                                'id' => $bill->order->id,
                                'course' => $bill->order->course ? [
                                    'id' => $bill->order->course->id,
                                    'title' => $bill->order->course->name_ar,
                                    'title_en' => $bill->order->course->name_en,
                                    'image' => $bill->order->course->image
                                ] : null,
                                'payment_method' => $bill->order->payment_method,
                                'payment_screenshot' => $bill->order->payment_screenshot,
                                'course_code' => $bill->order->courseCode ? [
                                    'code' => $bill->order->courseCode->code
                                ] : null,
                                'coupon' => $bill->order->coupon ? [
                                    'code' => $bill->order->coupon->code
                                ] : null,
                                'status' => $bill->order->status
                            ] : null,
                            'amount' => $bill->amount,
                            'tax_amount' => $bill->tax_amount,
                            'total_amount' => $bill->total_amount,
                            'payment_status' => $bill->payment_status,
                            'payment_status_display' => $this->getPaymentStatusDisplay($bill->payment_status),
                            'payment_method' => $bill->payment_method,
                            'due_date' => $bill->due_date,
                            'paid_at' => $bill->paid_at,
                            'notes' => $bill->notes,
                            'created_at' => $bill->created_at,
                            'updated_at' => $bill->updated_at
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $bills->currentPage(),
                        'last_page' => $bills->lastPage(),
                        'per_page' => $bills->perPage(),
                        'total' => $bills->total(),
                        'from' => $bills->firstItem(),
                        'to' => $bills->lastItem()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to retrieve billing history',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get payment method display name.
     */
    private function getPaymentMethodDisplay(mixed $paymentMethod): string
    {
        switch ($paymentMethod) {
            case 'online':
                return 'Online Payment';
            case 'course_code':
                return 'Course Code';
            case 'wallet':
                return 'Wallet Transfer';
            default:
                return ucfirst((string) $paymentMethod);
        }
    }

    /**
     * Get order status display name.
     */
    private function getOrderStatusDisplay(mixed $status): string
    {
        switch ($status) {
            case 'pending':
                return 'Pending Approval';
            case 'approved':
                return 'Approved';
            case 'rejected':
                return 'Rejected';
            case 'cancelled':
                return 'Cancelled';
            default:
                return ucfirst((string) $status);
        }
    }

    /**
     * Get payment status display name.
     */
    private function getPaymentStatusDisplay(mixed $status): string
    {
        switch ($status) {
            case 'pending':
                return 'Pending Payment';
            case 'paid':
                return 'Paid';
            case 'failed':
                return 'Payment Failed';
            case 'refunded':
                return 'Refunded';
            default:
                return ucfirst((string) $status);
        }
    }


}
