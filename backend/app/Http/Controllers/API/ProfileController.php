<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class ProfileController extends Controller
{
    /**
     * Get user profile with enrolled courses
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => new StudentProfileResource($user),
        ]);
    }

    /**
     * Update user profile (phone and email)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        $request->validate([
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'name' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            // This controls delivery to the device. The in-app notification
            // history remains available so important account activity is never lost.
            'notifications_status' => 'nullable|boolean',
            'watch_history_enabled' => 'nullable|boolean',
            'marketing_notifications_enabled' => 'nullable|boolean',
            'preferred_locale' => 'nullable|string|in:ar,en',
            'leaderboard_opt_in' => 'nullable|boolean',
            'autoplay_next_enabled' => 'nullable|boolean',
            'video_quality_preference' => 'nullable|string|in:auto,360p,480p,720p,1080p',
            'video_fit_mode' => 'nullable|string|in:cover,contain',
            'playback_speed' => 'nullable|numeric|in:0.5,0.75,1,1.25,1.5,1.75,2',
            'profile_image' => 'nullable|file|image|mimes:jpeg,png,jpg,webp|mimetypes:image/jpeg,image/png,image/webp|max:2048',
        ], [
            'phone.unique' => 'رقم الهاتف مسجل مسبقاً في حساب آخر',
            'email.unique' => 'البريد الإلكتروني مسجل مسبقاً في حساب آخر',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'name.max' => 'الاسم يجب ألا يتجاوز 255 حرفاً',
            'job_title.max' => 'مسمى الوظيفة يجب ألا يتجاوز 255 حرفاً',
            'profile_image.image' => 'يجب أن يكون الملف صورة',
            'profile_image.mimes' => 'يجب أن تكون الصورة من نوع JPEG أو PNG أو WebP',
            'profile_image.max' => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت',
        ]);

        $updateData = [];

        if ($request->has('phone') && $request->phone !== $user->phone) {
            $updateData['phone'] = $request->phone;
            // Reset phone verification when phone changes
            $updateData['phone_verified_at'] = null;
        }

        if ($request->has('email') && $request->email !== $user->email) {
            $updateData['email'] = $request->email;
            $updateData['email_verified_at'] = null;
        }

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->has('job_title')) {
            $updateData['job_title'] = $request->job_title;
        }

        if ($request->has('notifications_status')) {
            $updateData['notifications_status'] = $request->boolean('notifications_status');
        }

        if ($request->has('watch_history_enabled')) {
            $updateData['watch_history_enabled'] = $request->boolean('watch_history_enabled');
        }

        if ($request->has('marketing_notifications_enabled')) {
            $updateData['marketing_notifications_enabled'] = $request->boolean('marketing_notifications_enabled');
        }

        if ($request->has('preferred_locale')) {
            $updateData['preferred_locale'] = $request->string('preferred_locale')->lower()->value();
        }

        if ($request->has('leaderboard_opt_in')) {
            $updateData['leaderboard_opt_in'] = $request->boolean('leaderboard_opt_in');
        }

        if ($request->has('autoplay_next_enabled')) {
            $updateData['autoplay_next_enabled'] = $request->boolean('autoplay_next_enabled');
        }

        foreach (['video_quality_preference', 'video_fit_mode'] as $preference) {
            if ($request->has($preference)) {
                $updateData[$preference] = $request->string($preference)->lower()->value();
            }
        }

        if ($request->has('playback_speed')) {
            $updateData['playback_speed'] = (float) $request->input('playback_speed');
        }

        $newImagePath = null;
        $oldImagePath = $user->profile_image;
        try {
            if ($request->hasFile('profile_image')) {
                // Decode and re-encode the raster. This strips metadata and any
                // polyglot payload instead of trusting the extension alone.
                $newImagePath = $this->storeSafeProfileImage($request->file('profile_image'));
                $updateData['profile_image'] = $newImagePath;
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }
        } catch (Throwable $exception) {
            if ($newImagePath) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $exception;
        }

        if ($newImagePath && $oldImagePath && !filter_var($oldImagePath, FILTER_VALIDATE_URL)) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $phoneChanged = isset($updateData['phone']);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $phoneChanged
                ? 'تم تعديل البيانات بنجاح. يرجى إعادة تفعيل رقم الهاتف الجديد.'
                : 'تم تعديل البيانات بنجاح',
            'data' => new StudentProfileResource($user->fresh()),
            'requires_verification' => $phoneChanged,
        ]);
    }
    /**
     * Update user interests (classifications)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateInterests(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        $request->validate([
            'classification_ids' => 'required|array',
            'classification_ids.*' => 'exists:classifications,id',
        ]);

        $user->interests()->sync($request->classification_ids);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث الاهتمامات بنجاح',
            'data' => new StudentProfileResource($user->fresh()),
        ]);
    }

    private function storeSafeProfileImage(UploadedFile $file): string
    {
        $image = Image::make($file->getRealPath());
        // EXIF orientation is optional in PHP. A perfectly valid raster must
        // never turn into a 500 merely because a lightweight production image
        // omits the EXIF extension.
        if (function_exists('exif_read_data')) {
            $image->orientate();
        }
        $image->resize(1024, 1024, static function ($constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $canvas = Image::canvas($image->width(), $image->height(), '#ffffff')
            ->insert($image)
            ->encode('jpg', 86);
        $path = 'profiles/' . Str::uuid() . '.jpg';

        if (!Storage::disk('public')->put($path, (string) $canvas)) {
            throw new \RuntimeException('Could not store profile image.');
        }

        return $path;
    }

    /**
     * Clear only the learner's viewing history. Course completion and project
     * progress deliberately live in separate tables and are not touched.
     */
    public function clearWatchHistory(): JsonResponse
    {
        $user = auth('api')->user();
        $deleted = DB::table('watching_logs')->where('user_id', $user->id)->delete();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم مسح سجل المشاهدة',
            'data' => [
                'deleted_entries' => $deleted,
                'course_progress_preserved' => true,
            ],
        ]);
    }
}
