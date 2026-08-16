<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Course;
use App\Models\ItemList;
use App\Models\User;
use App\Models\Grade;
use App\Models\DesignSetting;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UrgentTasksController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display the urgent tasks dashboard.
     */
    public function index()
    {
        // Get pending orders
        $pendingOrders = Order::where('status', 'pending')
            ->with(['user', 'course', 'courseCode'])
            ->latest()
            ->get();

        // Get inactive students (users with active = false)
        $inactiveStudents = User::where('active', false)
            ->where('role', '!=', 'admin')
            ->latest('updated_at')
            ->get();

        // Get courses without quizzes
        $coursesWithoutQuiz = Course::leftJoin('lists', 'courses.id', '=', 'lists.course_id')
            ->whereNull('lists.id')
            ->select('courses.*')
            ->get();

        // Check for missing critical data
        $hasGrades = Grade::exists();
        $hasGroups = true;
        $hasCourses = Course::exists();

        // Calculate statistics
        $stats = [
            'pending_orders_count' => $pendingOrders->count(),
            'inactive_students_count' => $inactiveStudents->count(),
            'courses_without_quiz_count' => $coursesWithoutQuiz->count(),
            'total_urgent_tasks' => 0
        ];

        $stats['total_urgent_tasks'] = $stats['pending_orders_count'] +
                                     $stats['inactive_students_count'] +
                                     $stats['courses_without_quiz_count'];

        $designSettings = $this->getDesignSettings();
        
        return view('admin.urgent-tasks.index', compact(
            'pendingOrders',
            'inactiveStudents',
            'coursesWithoutQuiz',
            'stats',
            'hasGrades',
            'hasGroups',
            'hasCourses',
            'designSettings'
        ));
    }

    /**
     * Show pending orders.
     */
    public function pendingOrders()
    {
        $pendingOrders = Order::where('status', 'pending')
            ->with(['user', 'course', 'courseCode'])
            ->latest()
            ->paginate(20);

        $designSettings = $this->getDesignSettings();
        return view('admin.urgent-tasks.pending-orders', compact('pendingOrders', 'designSettings'));
    }

    /**
     * Show inactive students.
     */
    public function inactiveStudents()
    {
        $inactiveStudents = User::where('active', false)
            ->where('role', '!=', 'admin')
            ->latest('updated_at')
            ->paginate(20);

        $designSettings = $this->getDesignSettings();
        return view('admin.urgent-tasks.inactive-students', compact('inactiveStudents', 'designSettings'));
    }

    /**
     * Show courses without quizzes.
     */
    public function coursesWithoutQuiz()
    {
        $coursesWithoutQuiz = Course::leftJoin('lists', 'courses.id', '=', 'lists.course_id')
            ->whereNull('lists.id')
            ->select('courses.*')
            ->paginate(20);

        $designSettings = $this->getDesignSettings();
        return view('admin.urgent-tasks.courses-without-quiz', compact('coursesWithoutQuiz', 'designSettings'));
    }

    /**
     * Approve an order.
     */
    public function approveOrder(Request $request, Order $order)
    {
        return redirect()->route('admin.orders.show', $order)
            ->with('error', 'Use the audited order screen to approve this order.');

        $order->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        // Redirect back to the referring page
        $redirect = $request->get('redirect', 'pending-orders');
        if (str_contains($request->headers->get('referer'), 'pending-orders')) {
            return redirect()->route('admin.urgent-tasks.pending-orders')
                ->with('success', 'تم قبول الطلب بنجاح');
        } else {
            return redirect()->route('admin.urgent-tasks.index')
                ->with('success', 'تم قبول الطلب بنجاح');
        }
    }

    /**
     * Reject an order.
     */
    public function rejectOrder(Request $request, Order $order)
    {
        return redirect()->route('admin.orders.show', $order)
            ->with('error', 'Use the audited order screen to reject this order.');

        $order->update([
            'status' => 'rejected',
            'rejection_reason' => 'تم الرفض من لوحة التحكم',
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
        ]);

        // Redirect back to the referring page
        if (str_contains($request->headers->get('referer'), 'pending-orders')) {
            return redirect()->route('admin.urgent-tasks.pending-orders')
                ->with('success', 'تم رفض الطلب');
        } else {
            return redirect()->route('admin.urgent-tasks.index')
                ->with('success', 'تم رفض الطلب');
        }
    }

    /**
     * Activate a student (user).
     */
    public function activateStudent(Request $request, User $user)
    {
        $user->forceFill(['active' => true])->save();

        // Redirect back to the referring page
        if (str_contains($request->headers->get('referer'), 'inactive-students')) {
            return redirect()->route('admin.urgent-tasks.inactive-students')
                ->with('success', 'تم تفعيل الطالب بنجاح');
        } else {
            return redirect()->route('admin.urgent-tasks.index')
                ->with('success', 'تم تفعيل الطالب بنجاح');
        }
    }
}
