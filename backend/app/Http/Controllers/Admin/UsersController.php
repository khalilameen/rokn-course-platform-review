<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Order;
use App\Models\Bill;
use App\Models\DesignSetting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\StudentNotificationService;
use App\Services\AccountDeletionService;
use App\Services\DeviceLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        $users = User::query()->students()
            ->with(['latestNote']);

        // Filter by active status
        $activeFilter = $request->query('active');
        if (in_array($activeFilter, ['0', '1'], true)) {
            $users->where('active', $activeFilter === '1');
        }

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $users->where(function($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('name_ar', 'LIKE', "%{$search}%")
                      ->orWhere('name_en', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // Add pagination
        $users = $users->orderByDesc('id')->paginate(10)->appends($request->query());

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
    public function show(User $user, Request $request, DeviceLoginService $deviceLogin)
    {

        $user->loadCount('deviceTokens')->load([
            'socialAccounts' => fn ($accounts) => $accounts->orderBy('provider'),
        ]);

        // Get user orders with related data
        $orders = Order::where('user_id', $user->id)
            ->with(['course', 'coupon', 'courseCode', 'approvedBy', 'paymentMethod'])
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'orders_page');

        // Get user bills with related data
        $bills = Bill::where('user_id', $user->id)
            ->with(['order.course', 'order.paymentMethod'])
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'bills_page');

        // Get user notes with pagination
        $notes = $user->notes()->with('createdBy')->latest()->latest('id')->paginate(5, ['*'], 'notes_page');

        // Get user exam results with related data
        $examResults = \App\Models\ExamAttempt::where('user_id', $user->id)
            ->where('status', \App\Models\ExamAttempt::STATUS_COMPLETED)
            ->with(['quiz.course', 'quiz.lesson'])
            ->latest('completed_at')
            ->latest('id')
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

        $deviceLoginPolicy = $deviceLogin->configuredPolicy();

        // Get design settings
        $designSettings = $this->getDesignSettings();

        return view('admin.users.show', compact('user', 'orders', 'bills', 'notes', 'examResults', 'examStats', 'deviceLoginPolicy', 'designSettings'));
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
    public function destroy(User $user, AccountDeletionService $accounts)
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);

        $accounts->delete($user);

        return redirect()->route('admin.users.index')->with('success', 'تم حذف الحساب وبياناته الشخصية');
    }

    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deactive(Request $request, User $user)
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);
        $validated = $request->validate(['expected_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ((bool) $locked->active !== (bool) $validated['expected_active']) {
                throw ValidationException::withMessages([
                    'expected_active' => ['تغيّرت حالة الحساب بالفعل\nأعد تحميل الصفحة'],
                ]);
            }
            $active = !(bool) $locked->active;
            $locked->forceFill([
                'active' => $active,
                // Clear the retired single-token credential at the same time.
                'api_token' => $active ? $locked->getRawOriginal('api_token') : null,
            ])->save();

            if (!$active) {
                $locked->purgeApiTokens();
                $locked->deviceTokens()->delete();
            }

            // The previous implementation inverted the just-saved value here,
            // leaving a disabled learner's store active.
            if ($locked->store) {
                $locked->store->update(['active' => $active]);
            }
        }, 3);

        $user->refresh();
        return redirect()->back()->with('success', $user->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    public function sendNotification(Request $request, User $user)
    {
        $request->validate([
            'title'   => 'required|string|max:80',
            'message' => 'required|string|max:240',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:4096',
        ]);

        $title   = $request->input('title');
        $message = $request->input('message');
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('student-notifications', 'public');
            if (!is_string($imagePath) || trim($imagePath) === '') {
                throw ValidationException::withMessages(['image' => ['تعذّر حفظ الصورة']]);
            }
            $imageUrl = Storage::disk('public')->url($imagePath);
        }

        StudentNotificationService::notifyUser(
            $user,
            'admin_message',
            $title,
            $title,
            $message,
            $message,
            null,
            null,
            null,
            null,
            [],
            $imageUrl
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
    public function resetDevice(User $user, DeviceLoginService $deviceLogin)
    {
        if ($deviceLogin->configuredPolicy() === DeviceLoginService::POLICY_SINGLE_PERMANENT) {
            DB::transaction(function () use ($user): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                $locked->purgeApiTokens();
                $locked->deviceTokens()->delete();
                $locked->forceFill(['locked_device_id' => null])->save();
            }, 3);
            return redirect()->back()->with('success', 'تم إعادة تعيين الجهاز بنجاح. يمكن للطالب الآن تسجيل الدخول من جهاز جديد.');
        }

        return redirect()->back()->with('error', 'لا يمكن إعادة تعيين الجهاز في الوضع الحالي.');
    }
}
