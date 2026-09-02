<?php

use Illuminate\Support\Facades\Route;

Route::get('health/live', [\App\Http\Controllers\API\OperationalHealthController::class, 'live']);
Route::get('health/ready', [\App\Http\Controllers\API\OperationalHealthController::class, 'ready']);
Route::get('health/launch-ready', [\App\Http\Controllers\API\OperationalHealthController::class, 'launchReady']);
Route::post('store-notifications/google', [\App\Http\Controllers\API\StoreServerNotificationController::class, 'google'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('store-notifications/apple', [\App\Http\Controllers\API\StoreServerNotificationController::class, 'apple'])
    ->middleware(['recovery.write', 'throttle:store-notification']);
Route::post('integrations/bunny/stream', \App\Http\Controllers\API\BunnyStreamWebhookController::class)
    ->middleware('throttle:240,1');
Route::get('project-input-attachments/{attachment}/download', [\App\Http\Controllers\API\ProjectController::class, 'downloadInputAttachment'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('api.project-input-attachments.download');
Route::get('feedback/{publicId}/attachments/{attachment}', [\App\Http\Controllers\API\FeedbackController::class, 'attachment'])
    ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')
    ->whereNumber('attachment')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('api.feedback.attachment');

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

$registerCourseApiRoutes = function () {
        Route::post('whatsapp/webhook', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'webhook'])
            ->middleware('throttle:whatsapp-webhook');
        Route::get('product-features', [\App\Http\Controllers\API\ProductFeatureController::class, 'index'])
            ->middleware('throttle:60,1');
        Route::post('client-events', [\App\Http\Controllers\API\ClientEventController::class, 'store'])
            ->middleware('throttle:client-events');
        Route::post('product-events', [\App\Http\Controllers\API\ProductEventController::class, 'store'])
            ->middleware('throttle:product-events');
        Route::group(['middleware' => ['WebsiteVisitorCount']], function () {
            Route::get('main', [\App\Http\Controllers\API\HomeController::class,'mainPage']);
            Route::get('mobile-main-page', [\App\Http\Controllers\API\HomeController::class,'mobileMainPage']);
            Route::get('settings', [\App\Http\Controllers\API\HomeController::class,'settings']);
            Route::get('content/pages/{page}', [\App\Http\Controllers\API\PublicContentController::class, 'show'])
                ->whereIn('page', ['about', 'privacy', 'terms', 'returns', 'contact']);
            Route::post('contact', [\App\Http\Controllers\API\TasksController::class, 'contact'])
                ->middleware('throttle:5,1');
            Route::get('economy-config', [\App\Http\Controllers\API\LearningRewardController::class, 'configuration']);
            Route::get('public/portfolios/{slug}', [\App\Http\Controllers\API\PublicPortfolioController::class, 'show']);

            // App Version Check
            Route::post('app/check-version', [\App\Http\Controllers\API\AppVersionController::class, 'checkVersion']);

            /* ====== Sign =======*/
            // Mobile authentication is social-only. Keep the legacy URLs stable,
            // but never expose the old phone/password or OTP flows.
            // Keep public URLs stable without shadowing Laravel's web auth route names.
            Route::post('login', [\App\Http\Controllers\API\SignController::class,'otpDisabled'])->middleware('throttle:auth-api')->name('api.login');
            Route::post('register', [\App\Http\Controllers\API\SignController::class,'otpDisabled'])->middleware('throttle:auth-api')->name('api.register');
            Route::get('auth-methods', [\App\Http\Controllers\API\SignController::class,'authMethods'])->name('api.auth-methods');
            Route::post('social-login', [\App\Http\Controllers\API\SignController::class,'socialLogin'])->middleware('throttle:auth-api')->name('api.social-login');
            Route::get('social-auth/{socialProvider}/start', [\App\Http\Controllers\API\SocialOAuthController::class, 'start'])
                ->middleware('throttle:auth-api')
                ->name('api.social.start');
            Route::get('social-auth/{socialProvider}/callback', [\App\Http\Controllers\API\SocialOAuthController::class, 'callback'])
                ->middleware('throttle:auth-api')
                ->name('api.social.callback');
            Route::post('social-auth/complete', [\App\Http\Controllers\API\SocialOAuthController::class, 'complete'])
                ->middleware('throttle:auth-api')
                ->name('api.social.complete');
            Route::post('send-verification', [\App\Http\Controllers\API\SignController::class,'otpDisabled']);
            Route::post('verify-phone', [\App\Http\Controllers\API\SignController::class,'otpDisabled']);
            Route::post('forgot-password', [\App\Http\Controllers\API\SignController::class,'otpDisabled']);
            Route::post('reset-password', [\App\Http\Controllers\API\SignController::class,'otpDisabled']);

            Route::apiResource('admin_notification', \App\Http\Controllers\API\AdminNotificationsController::class, ['only' => ['index']]);
            Route::get('engagement/messages/{systemKey}', [\App\Http\Controllers\API\AdminNotificationsController::class, 'message'])
                ->whereIn('systemKey', ['guest_registration_prompt', 'welcome_bonus_received']);

            Route::middleware('auth:api')->group(function () {
                /*----Logout------*/
                Route::post('logout', [\App\Http\Controllers\API\SignController::class,'logout']);
                Route::post('delete-account', [\App\Http\Controllers\API\SignController::class,'deleteAccount'])->name('delete');
                Route::post('user/device-token', [\App\Http\Controllers\API\SignController::class, 'updateDeviceToken']);
                Route::delete('user/device-token', [\App\Http\Controllers\API\SignController::class, 'deleteDeviceToken']);
                Route::get('user/sessions', [\App\Http\Controllers\API\UserSessionController::class, 'index']);
                Route::delete('user/sessions', [\App\Http\Controllers\API\UserSessionController::class, 'destroyOthers'])
                    ->middleware('throttle:6,1');
                Route::delete('user/sessions/{sessionId}', [\App\Http\Controllers\API\UserSessionController::class, 'destroy'])
                    ->whereUuid('sessionId')
                    ->middleware('throttle:10,1');

                // Student Notifications
                Route::get('notifications/unread-count', [\App\Http\Controllers\API\StudentNotificationController::class, 'getUnreadCount']);
                Route::get('notifications/last-ten', [\App\Http\Controllers\API\StudentNotificationController::class, 'getLastTen']);
                Route::get('notifications', [\App\Http\Controllers\API\StudentNotificationController::class, 'getAll']);
                Route::get('notifications/{id}', [\App\Http\Controllers\API\StudentNotificationController::class, 'show'])
                    ->whereNumber('id');
                Route::post('notifications/{id}/mark-read', [\App\Http\Controllers\API\StudentNotificationController::class, 'markAsRead']);
                Route::post('notifications/mark-all-read', [\App\Http\Controllers\API\StudentNotificationController::class, 'markAllAsRead']);

                // Profile
                Route::get('user/profile', [\App\Http\Controllers\API\ProfileController::class,'index']);
                Route::get('user/paths', [\App\Http\Controllers\API\PathController::class, 'userPaths']);
                Route::put('user/profile', [\App\Http\Controllers\API\ProfileController::class,'update']);
                Route::post('user/interests', [\App\Http\Controllers\API\ProfileController::class,'updateInterests']);
                Route::get('user/watch-history', [\App\Http\Controllers\API\WatchHistoryController::class, 'index']);
                Route::post('user/watch-history', [\App\Http\Controllers\API\WatchHistoryController::class, 'store']);
                Route::post('lessons/{lesson}/playback-manifest', [\App\Http\Controllers\API\PlaybackController::class, 'manifest'])
                    ->middleware(['product.feature:playback', 'throttle:60,1']);
                Route::get('internal/playback/metrics', [\App\Http\Controllers\API\PlaybackMetricsController::class, 'index'])
                    ->middleware(['admin.only', 'throttle:30,1']);
                Route::delete('user/watch-history', [\App\Http\Controllers\API\ProfileController::class, 'clearWatchHistory']);
                Route::post('update_profile', [\App\Http\Controllers\API\ProfileController::class,'update']); // Legacy support

                // Project Routes
                Route::get('projects/{project}', [\App\Http\Controllers\API\ProjectController::class, 'show']);
                Route::post('projects/{project}/submissions', [\App\Http\Controllers\API\ProjectController::class, 'submit'])
                    ->middleware(['product.feature:project_uploads', 'throttle:8,1']);
                Route::get('project-submissions/{submission}', [\App\Http\Controllers\API\ProjectController::class, 'submissionStatus']);
                Route::get('project-feedback-threads/{thread}', [\App\Http\Controllers\API\ProjectController::class, 'feedbackThread']);
                Route::post('project-feedback-threads/{thread}/messages', [\App\Http\Controllers\API\ProjectController::class, 'sendFeedbackMessage'])
                    ->middleware('throttle:20,1');
                Route::post('project-feedback-threads/{thread}/attachments', [\App\Http\Controllers\API\ProjectController::class, 'uploadFeedbackAttachment'])
                    ->middleware('throttle:20,1');
                Route::get('project-submissions/{submission}/file', [\App\Http\Controllers\API\ProjectController::class, 'downloadSubmissionFile'])
                    ->name('api.project-submissions.file');
                Route::get('project-submission-attachments/{attachment}/file', [\App\Http\Controllers\API\ProjectController::class, 'downloadSubmissionAttachment'])
                    ->name('api.project-submission-attachments.file');
                Route::post('projects/{project}/evaluate', [\App\Http\Controllers\API\ProjectController::class, 'saveEvaluation']);
                Route::get('courses/{course}/project-evaluations', [\App\Http\Controllers\API\ProjectController::class, 'getUserProjectEvaluations']);

                // Certificates
                Route::get('certificates', [\App\Http\Controllers\API\CertificateController::class, 'index']);
                Route::get('certificates/{courseId}', [\App\Http\Controllers\API\CertificateController::class, 'show'])
                    ->whereNumber('courseId');
                // Explicit write-semantic recovery route used when completion
                // succeeded but asynchronous issuance did not leave a row.
                Route::post('certificates/{courseId}/issue', [\App\Http\Controllers\API\CertificateController::class, 'issue'])
                    ->whereNumber('courseId')
                    ->middleware('throttle:6,1');

                // Streaks
                Route::get('streaks', [\App\Http\Controllers\API\StreakController::class, 'index']);

                // Portfolio
                Route::get('portfolio', [\App\Http\Controllers\API\PortfolioController::class, 'index']);
                Route::get('portfolio/eligible-projects', [\App\Http\Controllers\API\PortfolioController::class, 'eligibleProjects']);
                Route::get('portfolio-profile', [\App\Http\Controllers\API\PortfolioController::class, 'profile']);
                Route::put('portfolio-profile', [\App\Http\Controllers\API\PortfolioController::class, 'updateProfile']);
                Route::post('portfolio', [\App\Http\Controllers\API\PortfolioController::class, 'store']);
                Route::get('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'show']);
                Route::post('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'update']); // Using POST for file update
                Route::post('portfolio/{id}/finalize', [\App\Http\Controllers\API\PortfolioController::class, 'finalize']);
                Route::delete('portfolio/{id}', [\App\Http\Controllers\API\PortfolioController::class, 'destroy']);

                // Portfolio Media
                Route::post('portfolio/{id}/media', [\App\Http\Controllers\API\PortfolioController::class, 'appendMedia']);
                Route::post('portfolio/{id}/media/video-uploads', [\App\Http\Controllers\API\PortfolioController::class, 'issueVideoUpload'])
                    ->middleware('throttle:10,1');
                Route::post('portfolio/{id}/media/video-uploads/renew', [\App\Http\Controllers\API\PortfolioController::class, 'renewVideoUpload'])
                    ->middleware('throttle:60,1');
                Route::post('portfolio/{id}/media/video-uploads/claim', [\App\Http\Controllers\API\PortfolioController::class, 'claimVideoUpload'])
                    ->middleware('throttle:20,1');
                Route::delete('portfolio/{id}/media/{mediaId}', [\App\Http\Controllers\API\PortfolioController::class, 'deleteMedia']);

                // Coin Earning Methods
                Route::get('coin-earning-methods', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'index']);
                Route::post('coin-earning-methods/{method}/start', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'start']);
                Route::post('claim-coins', [\App\Http\Controllers\API\CoinEarningMethodController::class, 'claim']);
                Route::get('engagement/next', [\App\Http\Controllers\API\EngagementController::class, 'next']);
                Route::get('whatsapp-connection', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'show']);
                Route::put('whatsapp-connection/consent', [\App\Http\Controllers\API\WhatsAppConnectionController::class, 'consent'])->middleware('throttle:10,1');

                // Wallet ledger
                Route::get('wallet', [\App\Http\Controllers\API\WalletController::class, 'show']);
                Route::get('wallet/transactions', [\App\Http\Controllers\API\WalletController::class, 'transactions']);
                Route::get('learning/courses', [\App\Http\Controllers\API\LearningDashboardController::class, 'courses']);
                Route::post('rewards/daily', [\App\Http\Controllers\API\LearningRewardController::class, 'daily']);

                // Course Codes routes
                Route::post('course-codes/redeem', [\App\Http\Controllers\API\CourseCodeController::class,'redeem'])
                    ->middleware('throttle:10,1');
                Route::post('course-codes/check', [\App\Http\Controllers\API\CourseCodeController::class,'check'])
                    ->middleware('throttle:20,1');
                Route::get('course-codes/my-codes', [\App\Http\Controllers\API\CourseCodeController::class,'myCodes']);

                // Course Authorization routes
                Route::get('courses/payment-methods', [\App\Http\Controllers\API\CourseAuthorizationController::class,'getPaymentMethods']);
                Route::post('courses/purchase-quote', [\App\Http\Controllers\API\CoursePurchaseController::class,'quote'])
                    ->middleware('throttle:20,1');
                Route::post('courses/authorize', [\App\Http\Controllers\API\CoursePurchaseController::class,'authorizeCourse'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);
                Route::get('courses/my-enrollments', [\App\Http\Controllers\API\CourseAuthorizationController::class,'myEnrollments']);
                Route::get('courses/my-orders', [\App\Http\Controllers\API\CourseAuthorizationController::class,'myCourseOrders']);
                Route::get('courses/my-bills', [\App\Http\Controllers\API\CourseAuthorizationController::class,'myBills']);
                Route::post('courses/check-access', [\App\Http\Controllers\API\CourseAuthorizationController::class,'checkAccess']);
                Route::post('course-chat/messages', [\App\Http\Controllers\API\CourseChatController::class, 'send'])
                    ->middleware(['product.feature:ai_chat', 'throttle:12,1']);
                Route::get('course-chat/messages', [\App\Http\Controllers\API\CourseChatController::class, 'history'])
                    ->middleware(['product.feature:ai_chat', 'throttle:30,1']);
                Route::get('course-chat/turns/{clientRequestId}', [\App\Http\Controllers\API\CourseChatController::class, 'status'])
                    ->whereUuid('clientRequestId')
                    ->middleware(['product.feature:ai_chat', 'throttle:60,1']);
                Route::delete('course-chat/turns/{clientRequestId}', [\App\Http\Controllers\API\CourseChatController::class, 'cancel'])
                    ->whereUuid('clientRequestId')
                    ->middleware(['product.feature:ai_chat', 'throttle:20,1']);
                Route::post('courses/{course}/chat', [\App\Http\Controllers\API\CourseChatController::class, 'sendForCourse'])
                    ->middleware(['product.feature:ai_chat', 'throttle:12,1']);
                Route::post('courses/{course}/chat/attachments', [\App\Http\Controllers\API\CourseChatController::class, 'uploadAttachment'])
                    ->middleware(['product.feature:ai_chat', 'throttle:20,1']);
                Route::get('ai-input-attachments/{attachment}', [\App\Http\Controllers\API\ProjectController::class, 'showInputAttachment'])
                    ->middleware('throttle:60,1');
                Route::get('courses/{course}/chat-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'quote']);
                Route::post('courses/{course}/chat-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'purchase'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);
                Route::get('courses/{course}/full-track-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'quote']);
                Route::post('courses/{course}/full-track-upgrade', [\App\Http\Controllers\API\CourseChatUpgradeController::class, 'purchase'])
                    ->middleware(['product.feature:checkout', 'throttle:6,1']);

                // Exam/Quiz routes
                Route::get('quizzes', [\App\Http\Controllers\API\CourseController::class,'getQuizzes']);
                Route::get('list/{list}', [\App\Http\Controllers\API\CourseController::class,'getList']);
                Route::get('question/{question}',  [\App\Http\Controllers\API\CourseController::class,'getQuestion']);
                Route::get('exams', [\App\Http\Controllers\API\CourseController::class,'getAllExams']);
                Route::get('random-quizzes', [\App\Http\Controllers\API\RandomQuizController::class, 'getAll']);
                Route::get('random-quizzes/{randomQuiz}', [\App\Http\Controllers\API\RandomQuizController::class, 'getRandomQuiz']);
                Route::get('exams/{quizId}/data', [\App\Http\Controllers\API\CourseController::class,'getExamData']);
                Route::get('courses/{courseId}/sections/{sectionId}/exam', [\App\Http\Controllers\API\CourseController::class,'getExamDataBySection']);

                // Exam Submission routes
                Route::post('exams/start', [\App\Http\Controllers\API\ExamController::class,'startExam']);
                Route::post('exams/submit-answer', [\App\Http\Controllers\API\ExamController::class,'submitAnswer']);
                Route::get('exams/{examAttemptId}/progress', [\App\Http\Controllers\API\ExamController::class,'getExamProgress']);
                Route::post('exams/end', [\App\Http\Controllers\API\ExamController::class,'endExam']);
                Route::post('exams/security-log', [\App\Http\Controllers\API\ExamController::class,'logSecurityEvent'])
                    ->middleware('throttle:60,1');
                Route::get('exams/history', [\App\Http\Controllers\API\ExamController::class,'getExamHistory']);
                Route::get('exams/results/', [\App\Http\Controllers\API\ExamController::class,'getAllExamResults']);
                Route::get('exams/{examAttemptId}/results', [\App\Http\Controllers\API\ExamController::class,'getExamResults']);

                Route::get('courses/{courseId}/progress', [\App\Http\Controllers\API\CourseController::class,'getCourseProgress']);
                Route::post('courses/{courseId}/sections/{sectionId}/complete', [\App\Http\Controllers\API\CourseController::class,'markSectionComplete']);

                // Metadata stays authenticated. Files use short-lived signed
                // downloads and the device's system viewer.
                Route::get('courses/{courseId}/pdfs', [\App\Http\Controllers\API\CoursePdfController::class,'index']);
                Route::get('courses/{courseId}/pdfs/{pdfId}', [\App\Http\Controllers\API\CoursePdfController::class,'show']);
                // Course Rating
                Route::post('courses/{courseId}/rate', [\App\Http\Controllers\API\CourseRatingController::class, 'store'])
                    ->middleware('throttle:12,1');
                Route::delete('courses/{courseId}/rate', [\App\Http\Controllers\API\CourseRatingController::class, 'destroy'])
                    ->middleware('throttle:12,1');

                // Saved Sections (Lesson Bookmarks)
                Route::get('saved-folders', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolders']);
                Route::get('saved-lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'getSavedLessons']);
                Route::get('saved-lessons/state', [\App\Http\Controllers\API\SavedSectionController::class, 'getSavedLessonState']);
                Route::post('saved-folders', [\App\Http\Controllers\API\SavedSectionController::class, 'createFolder']);
                Route::get('saved-lessons/{lessonId}/folders', [\App\Http\Controllers\API\SavedSectionController::class, 'getLessonFolders']);
                Route::get('saved-folders/{id}/lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolderLessons']);
                Route::get('saved-folders/{id}', [\App\Http\Controllers\API\SavedSectionController::class, 'getFolder']);
                Route::delete('saved-folders/{id}', [\App\Http\Controllers\API\SavedSectionController::class, 'deleteFolder']);
                Route::post('saved-folders/{id}/lessons', [\App\Http\Controllers\API\SavedSectionController::class, 'saveLesson']);
                Route::delete('saved-folders/{id}/lessons/{lessonId}', [\App\Http\Controllers\API\SavedSectionController::class, 'removeLesson']);
                Route::delete('saved-lessons/{lessonId}', [\App\Http\Controllers\API\SavedSectionController::class, 'removeLessonEverywhere']);

                // Kashier Package Payment (authenticated — mobile app uses these)
                Route::post('payment/initiate', [\App\Http\Controllers\API\PaymentController::class, 'initiate'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-write']);
                Route::get('payment/status/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'status'])
                    ->middleware('throttle:payment-read')
                    ->name('api.payment.status');
                Route::post('payment/reconcile/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'reconcile'])
                    ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                    ->name('api.payment.reconcile');
                Route::post('payment/abandon/{orderRef}', [\App\Http\Controllers\API\PaymentController::class, 'abandon'])
                    ->middleware(['recovery.write', 'throttle:payment-reconcile'])
                    ->name('api.payment.abandon');
                Route::get('store-billing/context', [\App\Http\Controllers\API\StorePurchaseController::class, 'context'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-read']);
                Route::post('store-purchases/verify', [\App\Http\Controllers\API\StorePurchaseController::class, 'verify'])
                    ->middleware(['product.feature:checkout', 'throttle:payment-write']);

            });

            // Native/system download clients and a link copied to a computer do
            // not forward the app bearer token. This URL is short-lived and its
            // signature binds the user and every resource key. The controller
            // still re-checks current enrollment and module access per request.
            Route::get('courses/{course}/modules/{module}/attachments/{attachment}/download', [\App\Http\Controllers\API\CourseModuleAttachmentController::class, 'download'])
                ->whereNumber('course')
                ->whereNumber('module')
                ->whereNumber('attachment')
                ->middleware(['signed', 'throttle:30,1'])
                ->name('api.course-module-attachments.download');
            Route::get('courses/{course}/pdfs/{pdf}/download', [\App\Http\Controllers\API\CoursePdfController::class, 'download'])
                ->whereNumber('course')
                ->whereNumber('pdf')
                ->middleware(['signed', 'throttle:30,1'])
                ->name('api.course-pdfs.download');

            // Compatibility only: course discovery uses /classifications.
            Route::get('categories', [\App\Http\Controllers\API\CategoryController::class,'index']);
            Route::get('courses', [\App\Http\Controllers\API\CourseController::class,'getCourses']);
            Route::get('course/{course}', [\App\Http\Controllers\API\CourseController::class,'getCourse']);

            // The old endpoint used implicit model binding before returning its
            // retirement response. That made existing and missing course ids
            // distinguishable (410 vs 404) and kept a GET-shaped mutation in
            // the public contract. Retire every id identically without loading
            // a course row.
            Route::match(['GET', 'POST'], 'courses/{legacyCourse}/subscribe', static function () {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'message' => 'هذا المسار لم يعد مستخدمًا',
                    'data' => null,
                ], 410);
            })->whereNumber('legacyCourse');
            Route::get('lesson/{lesson}', [\App\Http\Controllers\API\CourseController::class,'getLesson']);

            // Visitor Statistics Routes
            Route::get('visitors/stats', [\App\Http\Controllers\API\VisitorController::class, 'getStats'])
                ->middleware(['auth:api', 'admin']);
            Route::get('visitors/recent', [\App\Http\Controllers\API\VisitorController::class, 'getRecentVisitors'])
                ->middleware(['auth:api', 'admin']);

            // Grades routes
            Route::apiResource('grades', \App\Http\Controllers\API\GradeController::class)
                ->only(['index', 'show']);
            Route::get('grades/{grade}/courses', [\App\Http\Controllers\API\GradeController::class,'courses']);

             Route::get('feedback', [\App\Http\Controllers\API\FeedbackController::class, 'index'])
                 ->middleware('throttle:feedback');
             Route::post('feedback', [\App\Http\Controllers\API\FeedbackController::class, 'store'])
                 ->middleware(['recovery.write', 'throttle:feedback']);
             Route::get('feedback/{publicId}', [\App\Http\Controllers\API\FeedbackController::class, 'show'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:feedback');
             Route::post('feedback/{publicId}/messages', [\App\Http\Controllers\API\FeedbackController::class, 'reply'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['recovery.write', 'throttle:feedback']);
             Route::post('feedback/{publicId}/claim', [\App\Http\Controllers\API\FeedbackController::class, 'claim'])
                 ->where('publicId', '[0-9A-HJKMNP-TV-Z]{26}')->middleware(['auth:api', 'recovery.write', 'throttle:feedback']);

             // Compact, ranked catalogue search for the app search overlay.
             Route::get('search/courses', \App\Http\Controllers\API\CourseSearchController::class)
                 ->middleware('throttle:catalog-search');

             // Course Listing and Details routes
             Route::get('courses/list', [\App\Http\Controllers\API\CourseController::class,'listCourses']);
             Route::get('courses/{courseId}/details', [\App\Http\Controllers\API\CourseController::class,'viewCourseDetails']);

             // Packages
             Route::get('packages', [\App\Http\Controllers\API\PackageController::class, 'index']);
             Route::get('packages/{id}', [\App\Http\Controllers\API\PackageController::class, 'show']);
             Route::post('packages/{id}/purchase', [\App\Http\Controllers\API\PackageController::class, 'purchase'])
                 ->middleware(['auth:api', 'product.feature:checkout', 'throttle:payment-write']);

             // Classifications
             Route::get('classifications', [\App\Http\Controllers\API\ClassificationController::class, 'index']);
             Route::get('interests', [\App\Http\Controllers\API\ClassificationController::class, 'index']);

             // Paths
             Route::apiResource('paths', \App\Http\Controllers\API\PathController::class, ['only' => ['index', 'show']]);

        });
};

// Keep the versioned contract for new clients while preserving the deployed
// mobile team's historical /api/* URLs. The name prefix prevents duplicate
// route names from breaking route caching; generated signed URLs remain v1.
Route::prefix('v1')->group($registerCourseApiRoutes);
Route::name('legacy.')->group($registerCourseApiRoutes);
