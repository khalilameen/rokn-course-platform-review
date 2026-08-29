<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Order;
use App\Models\Bill;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsersController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $users = User::whereNotIn('role', ['admin', 'moderator', 'teacher'])
            ->with(['latestNote']);

        // Filter by active status
        if ($request->has('active') && $request->active == '1' || $request->active == '0') {
            $users->where('active', $request->active);
        }

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $users->where(function($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // Add pagination
        $users = $users->paginate(10)->appends($request->query());

        // Get design settings
        $designSettings = $this->getDesignSettings();

        return view('admin.users.index', compact('users', 'designSettings'));
    }


    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.users.create', compact('designSettings'));
    }


    /**
     * @param UserRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(UserRequest $request)
    {
        $validated = $request->validated();
        $user = new User();
        $user->name = $validated['name'];
        $user->email = strtolower(trim($validated['email']));
        $user->phone = trim($validated['phone']);
        $user->password = bcrypt($validated['password']);
        $user->forceFill([
            'role' => 'client',
            'active' => true,
            'is_online' => false,
        ])->save();

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $user->storeImage($file, 'users', 'featured');
        }

        return redirect()->route('admin.users.index')->with('success', 'تمت الإضافة بنجاح ');
    }


    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(User $user, Request $request)
    {

        $user->loadCount('deviceTokens');

        // Get user orders with related data
        $orders = Order::where('user_id', $user->id)
            ->with(['course', 'coupon', 'courseCode', 'approvedBy', 'paymentMethod'])
            ->latest()
            ->paginate(10, ['*'], 'orders_page');

        // Get user bills with related data
        $bills = Bill::where('user_id', $user->id)
            ->with(['order.course', 'order.paymentMethod'])
            ->latest()
            ->paginate(10, ['*'], 'bills_page');

        // Get user notes with pagination
        $notes = $user->notes()->with('createdBy')->latest()->paginate(5, ['*'], 'notes_page');

        // Get user exam results with related data
        $examResults = \App\Models\ExamAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\ExamAttempt::STATUS_COMPLETED)
            ->with(['quiz.course', 'quiz.lesson'])
            ->latest('completed_at')
            ->paginate(10, ['*'], 'exam_results_page');

        // Calculate exam statistics
        $totalExams = \App\Models\ExamAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\ExamAttempt::STATUS_COMPLETED)->count();
        $passedExams = \App\Models\ExamAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\ExamAttempt::STATUS_COMPLETED)
            ->where('is_passed', true)->count();
        $averageScore = \App\Models\ExamAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\ExamAttempt::STATUS_COMPLETED)
            ->avg('score_percentage') ?: 0;

        $examStats = [
            'total' => $totalExams,
            'passed' => $passedExams,
            'failed' => $totalExams - $passedExams,
            'average_score' => round($averageScore, 2),
            'pass_rate' => $totalExams > 0 ? round(($passedExams / $totalExams) * 100, 2) : 0
        ];

        // Get settings for device login policy
        $settings = Setting::first();

        // Get design settings
        $designSettings = $this->getDesignSettings();

        return view('admin.users.show', compact('user', 'orders', 'bills', 'notes', 'examResults', 'examStats', 'settings', 'designSettings'));
    }

    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(User $user)
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.users.edit', compact('user', 'designSettings'));
    }


    /**
     * @param UserRequest $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UserRequest $request, User $user)
    {

        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);

        $validated = $request->validated();
        $user->name = $validated['name'];
        $user->email = strtolower(trim($validated['email']));
        $user->phone = trim($validated['phone']);
        if (!empty($validated['password'])) {
            $user->password = bcrypt($validated['password']);
        }
        $user->save();

        return redirect()->route('admin.users.show', $user->id)->with('success', 'تم التعديل بنجاح');
    }


    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy(User $user)
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'تم الحذف بنجاح ');
    }

    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deactive(User $user)
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);
        DB::transaction(function () use ($user): void {
            $active = !(bool) $user->active;
            $user->forceFill([
                'active' => $active,
                // Clear the retired single-token credential at the same time.
                'api_token' => $active ? $user->getRawOriginal('api_token') : null,
            ])->save();

            if (!$active) {
                $user->purgeApiTokens();
            }

            // The previous implementation inverted the just-saved value here,
            // leaving a disabled learner's store active.
            if ($user->store) {
                $user->store->update(['active' => $active]);
            }
        }, 3);

        $user->refresh();
        return redirect()->back()->with('success', $user->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    public function sendNotification(Request $request, User $user)
    {
        $request->validate([
            'title'   => 'required|string|max:255',
            'message' => 'required|string|max:1000',
        ]);

        $title   = $request->input('title');
        $message = $request->input('message');

        StudentNotificationService::notifyUser(
            $user,
            'admin_message',
            $title,
            $title,
            $message,
            $message
        );

        $canReceivePush = (bool) $user->notifications_status
            && $user->deviceTokens()->exists();

        return redirect()->back()->with(
            $canReceivePush ? 'success' : 'warning',
            $canReceivePush
                ? 'تم حفظ الإشعار وإضافته إلى قائمة إرسال الهاتف'
                : 'تم حفظ الإشعار داخل حساب الطالب، لكن إشعار الهاتف لن يصل حتى يفعّل الطالب الإشعارات ويسجل جهازه'
        );
    }

    /**
     * Store a new note for the user.
     */
    public function storeNote(Request $request, User $user)
    {
        $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $user->addNote();

        return redirect()->back()->with('success', 'تم إضافة الملاحظة بنجاح');
    }

    /**
     * Delete a note.
     */
    public function deleteNote(UserNote $note)
    {
        // Check if the current user can delete this note
        if ($note->created_by !== auth()->id() && auth()->user()->role !== 'admin') {
            return redirect()->back()->with('error', 'غير مصرح لك بحذف هذه الملاحظة');
        }

        $note->delete();

        return redirect()->back()->with('success', 'تم حذف الملاحظة بنجاح');
    }

    /**
     * Reset the locked device for a user (single_device_permanent policy).
     */
    public function resetDevice(User $user)
    {
        // Check if device login policy is single_device_permanent
        $settings = Setting::first();

        if ($settings) {
            $user->forceFill(['locked_device_id' => null])->save();
            return redirect()->back()->with('success', 'تم إعادة تعيين الجهاز بنجاح. يمكن للطالب الآن تسجيل الدخول من جهاز جديد.');
        }

        return redirect()->back()->with('error', 'لا يمكن إعادة تعيين الجهاز في الوضع الحالي.');
    }
}
