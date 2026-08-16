<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeedbackReport;
use App\Models\Lesson;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Intervention\Image\Facades\Image;
use Throwable;

final class FeedbackController extends Controller
{
    public function store(Request $request, ApiResponseService $responses): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(['bug', 'suggestion', 'course_content', 'playback'])],
            'message' => 'required|string|min:10|max:2000',
            'screen_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'],
            'course_id' => 'nullable|integer|exists:courses,id',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'app_version' => 'nullable|string|max:32',
            'build_number' => 'nullable|integer|min:1|max:2147483647',
            'os_major' => 'nullable|integer|min:1|max:255',
            'locale' => 'nullable|string|max:16',
            'screen_size' => ['nullable', 'string', 'max:32', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'font_scale' => 'nullable|numeric|min:0.5|max:4',
            'device_tier' => 'nullable|string|max:24',
            'network_type' => 'nullable|string|max:24',
            'screenshot' => 'nullable|file|image|mimes:jpeg,jpg,png,webp|mimetypes:image/jpeg,image/png,image/webp|max:4096|dimensions:max_width=4096,max_height=4096',
        ]);

        if (!empty($validated['lesson_id']) && !empty($validated['course_id'])) {
            $belongs = Lesson::query()->whereKey($validated['lesson_id'])
                ->where('list_id', $validated['course_id'])->exists();
            abort_unless($belongs, 422, 'The selected lesson does not belong to the course.');
        }

        $storedPath = null;
        try {
            $report = DB::transaction(function () use ($request, $validated, &$storedPath): FeedbackReport {
                $user = auth('api')->user();
                $report = FeedbackReport::create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $user?->id,
                    'course_id' => $validated['course_id'] ?? null,
                    'lesson_id' => $validated['lesson_id'] ?? null,
                    'category' => $validated['category'],
                    'status' => 'new',
                    'priority' => 'normal',
                    'message' => trim($validated['message']),
                    'screen_key' => $validated['screen_key'] ?? null,
                    'platform' => $validated['platform'] ?? null,
                    'app_version' => $validated['app_version'] ?? null,
                    'build_number' => $validated['build_number'] ?? null,
                    'os_major' => $validated['os_major'] ?? null,
                    'locale' => $validated['locale'] ?? null,
                    'screen_size' => $validated['screen_size'] ?? null,
                    'font_scale' => $validated['font_scale'] ?? null,
                    'device_tier' => $validated['device_tier'] ?? null,
                    'network_type' => $validated['network_type'] ?? null,
                    'context' => array_filter([
                        'request_id' => $request->header('X-Request-Id'),
                    ]),
                    'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                ]);

                if ($request->hasFile('screenshot')) {
                    $image = Image::make($request->file('screenshot')->getRealPath());
                    if (function_exists('exif_read_data')) $image->orientate();
                    $image->resize(2048, 2048, static function ($constraint): void {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                    $encoded = (string) $image->encode('jpg', 86);
                    $storedPath = now()->format('Y/m').'/'.$report->public_id.'.jpg';
                    if (!Storage::disk('feedback')->put($storedPath, $encoded)) {
                        throw new \RuntimeException('Could not store feedback screenshot.');
                    }
                    $report->attachments()->create([
                        'disk' => 'feedback',
                        'path' => $storedPath,
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => strlen($encoded),
                        'width' => $image->width(),
                        'height' => $image->height(),
                    ]);
                }

                return $report;
            });
        } catch (Throwable $exception) {
            if ($storedPath) Storage::disk('feedback')->delete($storedPath);
            throw $exception;
        }

        return $responses->success(
            [
                'public_id' => $report->public_id,
                'status' => $report->status,
                'created_at' => $report->created_at->toIso8601String(),
            ],
            'Feedback received successfully',
            201
        );
    }
}
