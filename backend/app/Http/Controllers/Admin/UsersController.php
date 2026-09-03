<?php

namespace App\Http\Controllers\Admin;

use App\Support\PublicDiskUrl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Order;
use App\Models\Bill;
use App\Models\DesignSetting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\StudentNotificationService;
use App\Services\DeviceLoginService;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\StudentAccountStateService;
use App\Services\PaymentChannelReportService;
use App\Services\WalletQueryService;
use App\Support\AdminEditorVersion;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request, StudentAccountStateService $accounts)
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
        $accountStateVersions = $users->getCollection()->mapWithKeys(
            fn (User $user): array => [$user->id => $accounts->editorVersion($user)]
        );

        // Get design settings
        $designSettings = $this->getDesignSettings();

        return view('admin.users.index', compact('users', 'designSettings', 'accountStateVersions'));
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
    public function store(UserRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validated();
        $requestId = (string) $validated['authoring_request_id'];
        $user = User::withTrashed()->where('authoring_request_id', $requestId)->first();
        if (!$user) {
            $user = DB::transaction(function () use (
                $request,
                $validated,
                $requestId,
                $createIntents
            ): User {
                $user = new User();
                $user->name = $validated['name'];
                $user->email = strtolower(trim($validated['email']));
                $user->phone = trim($validated['phone']);
                // Learner authentication is social-only. Keep a non-usable
                // database value for the legacy non-null column without
                // presenting or accepting a password credential in the admin.
                $user->password = bcrypt(\Illuminate\Support\Str::random(64));
                $user->authoring_request_id = $requestId;
                $user->forceFill([
                    'role' => 'client',
                    'active' => true,
                    'is_online' => false,
                    // Creating a learner in the dashboard reserves the row;
                    // it does not prove ownership of the email address. The
                    // linked social provider remains the only identity proof.
                    'email_verified_at' => null,
                ])->save();
                $createIntents->checkpointResource($request, User::class, $user->id);
                return $user;
            }, 3);
        } else {
            DB::transaction(function () use ($request, $user, $createIntents): void {
                User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource($request, User::class, $user->id);
            }, 3);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $user->storeImage(
                $file,
                'users',
                'featured',
                'admin-student|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        DB::transaction(function () use ($request, $user, $createIntents): void {
            $locked = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $createIntents->completeRedirect(
                $request,
                route('admin.users.index'),
                302,
                User::class,
                $locked->id
            );
        }, 3);

        return redirect()->route('admin.users.index')->with('success', 'تمت الإضافة بنجاح ');
    }


    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(
        User $user,
        Request $request,
        DeviceLoginService $deviceLogin,
        StudentAccountStateService $accounts,
        PaymentChannelReportService $paymentChannels,
        WalletQueryService $wallet
    )
    {
        $this->assertStudent($user);

        $user->loadCount('deviceTokens')->load([
            'socialAccounts' => fn ($accounts) => $accounts->orderBy('provider'),
        ]);

        // Get user orders with related data
        $orderScope = Order::where('user_id', $user->id);
        $orders = (clone $orderScope)
            ->with(['course', 'coupon', 'courseCode', 'approvedBy', 'paymentMethod'])
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'orders_page');

        // Get user bills with related data
        $billScope = Bill::where('user_id', $user->id);
        $bills = (clone $billScope)
            ->with(['order.course', 'order.paymentMethod'])
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'bills_page');

        $paymentReport = $paymentChannels->summary(null, null, clone $orderScope);
        // Keep the dashboard on the same wallet contract as the mobile app.
        // Rebuilding the split from orders would miss rewards, compensation
        // and unspent package credits.
        $walletSummary = $wallet->summary($user);
        $orderStats = [
            'approved' => (clone $orderScope)->where('status', Order::STATUS_APPROVED)->count(),
            'pending' => (clone $orderScope)->where('status', Order::STATUS_PENDING)->count(),
            'approved_coins' => (int) (clone $orderScope)
                ->where('status', Order::STATUS_APPROVED)
                ->sum('total_coins'),
            'confirmed_egp' => (float) $paymentReport['egp']['confirmed_gross_amount'],
        ];
        $coinMethods = [Bill::PAYMENT_METHOD_WALLET, Order::PAYMENT_METHOD_WALLET_COINS];
        $billStats = [
            'paid' => (clone $billScope)->where('payment_status', Bill::PAYMENT_STATUS_PAID)->count(),
            'pending' => (clone $billScope)->where('payment_status', Bill::PAYMENT_STATUS_PENDING)->count(),
            'paid_coins' => (float) (clone $billScope)
                ->where('payment_status', Bill::PAYMENT_STATUS_PAID)
                ->whereIn('payment_method', $coinMethods)
                ->sum('total_amount'),
            'pending_coins' => (float) (clone $billScope)
                ->where('payment_status', Bill::PAYMENT_STATUS_PENDING)
                ->whereIn('payment_method', $coinMethods)
                ->sum('total_amount'),
            'paid_egp' => (float) (clone $billScope)
                ->where('payment_status', Bill::PAYMENT_STATUS_PAID)
                ->whereNotIn('payment_method', $coinMethods)
                ->sum('total_amount'),
            'pending_egp' => (float) (clone $billScope)
                ->where('payment_status', Bill::PAYMENT_STATUS_PENDING)
                ->whereNotIn('payment_method', $coinMethods)
                ->sum('total_amount'),
        ];

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

        $accountStateVersion = $accounts->editorVersion($user);
        $deviceStateVersion = $this->deviceEditorVersion($user);
        return view('admin.users.show', compact(
            'user', 'orders', 'bills', 'notes', 'examResults', 'examStats',
            'deviceLoginPolicy', 'designSettings', 'accountStateVersion',
            'deviceStateVersion', 'orderStats', 'billStats', 'walletSummary'
        ));
    }

    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(User $user)
    {
        $this->assertStudent($user);
        $designSettings = $this->getDesignSettings();
        $editorVersion = $this->editorVersion($user);
        return view('admin.users.edit', compact('user', 'designSettings', 'editorVersion'));
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
        $editorVersion = (string) $validated['editor_version'];
        DB::transaction(function () use ($user, $validated, $editorVersion): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => ['تغيّرت بيانات الطالب منذ فتح الصفحة\nأعد تحميلها قبل الحفظ'],
                ]);
            }

            $email = strtolower(trim((string) $validated['email']));
            $updates = [
                'name' => $validated['name'],
                'email' => $email,
                'phone' => trim((string) $validated['phone']),
                'profile_revision' => (int) $locked->profile_revision + 1,
            ];
            if (!hash_equals(strtolower(trim((string) $locked->email)), $email)) {
                $updates['email_verified_at'] = null;
            }
            $locked->forceFill($updates)->save();
        }, 3);

        return redirect()->route('admin.users.show', $user->id)->with('success', 'تم التعديل بنجاح');
    }


    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deactive(
        Request $request,
        User $user,
        StudentAccountStateService $accounts
    )
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);
        $validated = $request->validate([
            'expected_active' => ['required', 'boolean'],
            'state_version' => ['required', 'string', 'size:64'],
        ]);
        $user = $accounts->setActive(
            $user,
            (bool) $validated['expected_active'],
            (string) $validated['state_version'],
            !(bool) $validated['expected_active']
        );
        return redirect()->back()->with('success', $user->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    private function editorVersion(User $user): string
    {
        return AdminEditorVersion::for($user, [
            'name', 'email', 'phone', 'profile_revision', 'email_verified_at',
        ]);
    }

    private function deviceEditorVersion(User $user): string
    {
        return AdminEditorVersion::for($user, [
            'locked_device_id', 'profile_revision', 'deleted_at',
        ]);
    }

    public function sendNotification(Request $request, User $user)
    {
        $this->assertStudent($user);
        if (!(bool) $user->active || $user->trashed()) {
            throw ValidationException::withMessages([
                'message' => ['هذا الحساب غير نشط\nفعّله قبل إرسال إشعار'],
            ]);
        }
        $request->validate([
            'title'   => 'required|string|max:80',
            'message' => 'required|string|max:240',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:4096',
            'authoring_request_id' => 'required|uuid',
        ]);

        $title = trim((string) $request->input('title'));
        $message = trim((string) $request->input('message'));
        $deliveryKey = 'admin-message:' . auth()->id() . ':' . $user->id . ':'
            . strtolower((string) $request->input('authoring_request_id'));
        if (strlen($deliveryKey) > 64) $deliveryKey = hash('sha256', $deliveryKey);
        $existing = \App\Models\StudentNotification::query()
            ->where('user_id', $user->id)
            ->where('delivery_key', $deliveryKey)
            ->first();
        if ($existing) {
            $existingHasImage = trim((string) $existing->image_url) !== '';
            $replayHasImage = $request->hasFile('image');
            if (!hash_equals((string) $existing->title_ar, $title)
                || !hash_equals((string) $existing->message_ar, $message)
                || $existingHasImage !== $replayHasImage
                || ($replayHasImage && !$this->notificationImageMatches(
                    (string) $existing->image_url,
                    $request->file('image'),
                    'notification-user|' . $deliveryKey
                ))) {
                throw ValidationException::withMessages([
                    'authoring_request_id' => ['تغيّرت بيانات الإشعار\nأعد فتح النموذج ثم أرسل'],
                ]);
            }
            return $this->directNotificationResponse($user);
        }
        $imageUrl = null;
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $image,
                'student-notifications',
                'public',
                60,
                'notification-user|' . $deliveryKey . '|' . hash_file('sha256', $image->getRealPath())
            );
            if (!is_string($imagePath) || trim($imagePath) === '') {
                throw ValidationException::withMessages(['image' => ['تعذّر حفظ الصورة']]);
            }
            $imageUrl = PublicDiskUrl::from($imagePath);
        }

        $committed = false;
        try {
            $notification = StudentNotificationService::notifyUser(
                $user,
                'admin_message',
                $title,
                $title,
                $message,
                $message,
                null,
                null,
                null,
                $deliveryKey,
                [],
                $imageUrl
            );
            if (!$notification) {
                throw ValidationException::withMessages([
                    'message' => ['لم يُحفظ الإشعار\nحدّث الصفحة ثم حاول مرة أخرى'],
                ]);
            }
            if ($imageUrl !== null && !hash_equals((string) $notification->image_url, $imageUrl)) {
                throw ValidationException::withMessages([
                    'authoring_request_id' => ['حُفظت محاولة مختلفة لهذا الإشعار\nأعد فتح النموذج'],
                ]);
            }
            $committed = true;
        } finally {
            if (!$committed && is_string($imagePath) && $imagePath !== '') {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $imagePath);
            }
        }

        return $this->directNotificationResponse($user);
    }

    private function notificationImageMatches(string $url, UploadedFile $image, string $identityPrefix): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $storedIdentity = pathinfo($path, PATHINFO_FILENAME);
        $contentHash = hash_file('sha256', $image->getRealPath());

        return $storedIdentity !== '' && hash_equals(
            $storedIdentity,
            hash('sha256', $identityPrefix . '|' . $contentHash)
        );
    }

    private function directNotificationResponse(User $user)
    {
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
    public function storeNote(
        Request $request,
        User $user,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $validated = $request->validate([
            'note' => 'required|string|max:1000',
            'authoring_request_id' => 'required|uuid',
        ]);

        DB::transaction(function () use ($request, $user, $validated, $createIntents): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            $note = $locked->notes()->create([
                'note' => $validated['note'],
                'created_by' => auth()->id(),
            ]);
            $createIntents->checkpointResource($request, UserNote::class, $note->id);
            $createIntents->completeRedirect(
                $request,
                route('admin.users.show', $locked->id),
                302,
                UserNote::class,
                $note->id
            );
        }, 3);

        return redirect()->route('admin.users.show', $user->id)
            ->with('success', 'تم إضافة الملاحظة بنجاح');
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
    public function resetDevice(
        Request $request,
        User $user,
        DeviceLoginService $deviceLogin
    )
    {
        $validated = $request->validate([
            'state_version' => ['required', 'string', 'size:64'],
            'expected_policy' => ['required', 'string'],
        ]);
        $policy = $deviceLogin->configuredPolicy();
        if (
            $validated['expected_policy'] !== DeviceLoginService::POLICY_SINGLE_PERMANENT
            || $policy !== DeviceLoginService::POLICY_SINGLE_PERMANENT
        ) {
            throw ValidationException::withMessages([
                'expected_policy' => ['تغيّرت سياسة الأجهزة\nأعد تحميل الصفحة'],
            ]);
        }

        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            if (
                trim((string) $locked->locked_device_id) === ''
                || !hash_equals($this->deviceEditorVersion($locked), (string) $validated['state_version'])
            ) {
                throw ValidationException::withMessages([
                    'state_version' => ['تغيّرت جلسات الطالب بالفعل\nأعد تحميل الصفحة'],
                ]);
            }

            $locked->purgeApiTokens();
            $locked->deviceTokens()->delete();
            $locked->forceFill([
                'locked_device_id' => null,
                'profile_revision' => (int) $locked->profile_revision + 1,
            ])->save();
        }, 3);

        return redirect()->back()->with(
            'success',
            'تم إعادة تعيين الجهاز بنجاح. يمكن للطالب الآن تسجيل الدخول من جهاز جديد.'
        );
    }

    private function assertStudent(User $user): void
    {
        abort_unless(strtolower(trim((string) $user->role)) === 'client', 404);
    }
}
