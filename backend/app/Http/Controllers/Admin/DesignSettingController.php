<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Services\PublicAppSettingsService;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Validation\ValidationException;

final class DesignSettingController extends Controller
{
    public function index(): View
    {
        $settings = DesignSetting::getDefaultSettings();
        return view('admin.design-settings.index', [
            'settings' => $settings,
            'editorVersion' => $this->editorVersion($settings),
        ]);
    }

    /**
     * The dashboard owns one design-settings record. The same endpoint creates
     * it on first use and updates it afterwards, so there is no second editor
     * with a competing contract.
     */
    public function store(
        Request $request,
        PublicAppSettingsService $publicSettings,
        StoredFileDeletionService $storedFiles,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $submittedEditorVersion = (string) $validated['editor_version'];
        foreach (['facebook', 'youtube', 'instagram', 'tiktok', 'telegram'] as $channel) {
            $field = "{$channel}_url";
            $value = $validated[$field] ?? null;
            if ($value === null || trim((string) $value) === '') {
                $validated[$field] = null;
                continue;
            }
            $validated[$field] = $publicSettings->socialUrl($channel, $value);
            if ($validated[$field] === null) {
                throw ValidationException::withMessages([
                    $field => ['أدخل رابط الحساب الصحيح لهذه المنصة يبدأ بـ https'],
                ]);
            }
        }
        if (!empty($validated['whatsapp_url'])) {
            $validated['whatsapp_url'] = $publicSettings->whatsAppUrl($validated['whatsapp_url']);
            if ($validated['whatsapp_url'] === null) {
                throw ValidationException::withMessages([
                    'whatsapp_url' => ['أدخل رقمًا دوليًا أو رابطًا صحيحًا يبدأ بـ https://wa.me/'],
                ]);
            }
        }
        if (!empty($validated['how_platform_works_video_link'])) {
            $validated['how_platform_works_video_link'] = $publicSettings->embedVideoUrl(
                $validated['how_platform_works_video_link']
            );
            if ($validated['how_platform_works_video_link'] === null) {
                throw ValidationException::withMessages([
                    'how_platform_works_video_link' => ['استخدم رابط فيديو من YouTube أو Vimeo'],
                ]);
            }
        }
        if ($request->boolean('show_how_platform_works') && empty($validated['how_platform_works_video_link'])) {
            throw ValidationException::withMessages([
                'how_platform_works_video_link' => ['أضف رابط الفيديو قبل إظهار هذا القسم'],
            ]);
        }
        $data = collect($validated)->except([
            'logo_file',
            'icon_file',
            'home_background_file',
            'powered_by_titles',
            'powered_by_urls',
            'editor_version',
            'authoring_request_id',
        ])->all();
        $data['show_how_platform_works'] = $request->boolean('show_how_platform_works');
        $data['powered_by'] = $this->normalisePartners(
            $validated['powered_by_titles'] ?? [],
            $validated['powered_by_urls'] ?? []
        );

        $settings = DesignSetting::query()->first();
        $newFiles = [];
        $oldFiles = [];

        try {
            foreach ([
                'logo_file' => ['logo_url', 'design-settings/logos'],
                'icon_file' => ['icon_url', 'design-settings/icons'],
                'home_background_file' => ['home_background_url', 'design-settings/home-backgrounds'],
            ] as $input => [$attribute, $directory]) {
                if (!$request->hasFile($input)) {
                    continue;
                }

                $path = $storedFiles->storeTrackedUpload($request->file($input), $directory);

                $newFiles[] = $path;
                $data[$attribute] = Storage::disk('public')->url($path);
                if ($settings?->{$attribute}) {
                    $oldFiles[] = $this->publicPathFromUrl((string) $settings->{$attribute});
                }
            }

            DB::transaction(function () use (
                &$settings,
                $data,
                $submittedEditorVersion,
                $request,
                $createIntents
            ): void {
                $locked = DesignSetting::query()->lockForUpdate()->first();
                $current = $locked ?: DesignSetting::getDefaultSettings();
                if (!hash_equals($this->editorVersion($current), $submittedEditorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => ['عدّل شخص آخر إعدادات التصميم\nأعد تحميل الصفحة قبل الحفظ'],
                    ]);
                }
                if ($locked) {
                    $locked->update($data);
                    $settings = $locked;
                } else {
                    $settings = DesignSetting::create($data);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.design-settings.index'),
                    302,
                    DesignSetting::class,
                    $settings->id
                );
            });
        } catch (\Throwable $exception) {
            foreach ($newFiles as $path) {
                $storedFiles->deleteOrQueue('public', $path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);

            return back()->withInput()->with('error', 'تعذر حفظ الإعدادات الآن');
        }

        foreach (array_filter($oldFiles) as $path) {
            $storedFiles->deleteOrQueue('public', $path);
        }

        return redirect()->route('admin.design-settings.index')
            ->with('success', 'تم حفظ إعدادات التصميم');
    }

    /** @return array<string, string|array<int, string>> */
    private function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slogan_1_ar' => ['nullable', 'string', 'max:255'],
            'slogan_1_en' => ['nullable', 'string', 'max:255'],
            'slogan_2_ar' => ['nullable', 'string', 'max:255'],
            'slogan_2_en' => ['nullable', 'string', 'max:255'],
            'slogan_3_ar' => ['nullable', 'string', 'max:255'],
            'slogan_3_en' => ['nullable', 'string', 'max:255'],
            'color_1' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_2' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_3' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_4' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:1524'],
            'icon_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:1524'],
            'home_background_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'facebook_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'youtube_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'instagram_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'tiktok_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'whatsapp_url' => ['nullable', 'string', 'max:2048'],
            'telegram_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'technical_contact' => ['nullable', 'string', 'max:32'],
            'policy_content_ar' => ['nullable', 'string'],
            'policy_content_en' => ['nullable', 'string'],
            'powered_by_titles' => ['nullable', 'array', 'max:20'],
            'powered_by_titles.*' => ['nullable', 'string', 'max:100'],
            'powered_by_urls' => ['nullable', 'array', 'max:20'],
            'powered_by_urls.*' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'show_how_platform_works' => ['nullable', 'boolean'],
            'how_platform_works_title_ar' => ['nullable', 'string', 'max:255'],
            'how_platform_works_title_en' => ['nullable', 'string', 'max:255'],
            'how_platform_works_video_link' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'editor_version' => ['required', 'string', 'size:64'],
            'authoring_request_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @param array<int, mixed> $titles
     * @param array<int, mixed> $urls
     * @return list<array{title: string, url: string}>
     */
    private function normalisePartners(array $titles, array $urls): array
    {
        $partners = [];
        foreach ($titles as $index => $title) {
            $title = trim((string) $title);
            $url = trim((string) ($urls[$index] ?? ''));
            if ($title !== '' && $url !== '') {
                $partners[] = ['title' => $title, 'url' => $url];
            }
        }

        return $partners;
    }

    private function publicPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/storage/')) {
            return null;
        }

        return ltrim(substr($path, strlen('/storage/')), '/');
    }

    private function editorVersion(DesignSetting $settings): string
    {
        return hash('sha256', implode('|', [
            (string) ($settings->id ?? 'new'),
            (string) optional($settings->updated_at)->format('Y-m-d H:i:s.u'),
        ]));
    }
}
