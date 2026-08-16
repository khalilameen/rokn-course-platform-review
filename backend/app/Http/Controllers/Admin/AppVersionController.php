<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppVersion;
use Illuminate\Validation\Rule;

class AppVersionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $versions = AppVersion::orderBy('id', 'desc')->paginate(10);
        return view('admin.app-versions.index', compact('versions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.app-versions.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $this->validatedPayload($request);

        AppVersion::create($data);

        return redirect()->route('admin.app-versions.index')->with('success', 'تم إضافة الإصدار بنجاح');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $version = AppVersion::findOrFail($id);
        return view('admin.app-versions.show', compact('version'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $version = AppVersion::findOrFail($id);
        return view('admin.app-versions.edit', compact('version'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $data = $this->validatedPayload($request, $version->id);

        $version->update($data);

        return redirect()->route('admin.app-versions.index')->with('success', 'تم تحديث الإصدار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $version = AppVersion::findOrFail($id);
        $version->delete();

        return redirect()->route('admin.app-versions.index')->with('success', 'تم حذف الإصدار بنجاح');
    }

    /**
     * Toggle the active status of the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleActive($id)
    {
        $version = AppVersion::findOrFail($id);

        if (!$version->is_active && !$this->isActivatable($version)) {
            return redirect()->back()->with(
                'error',
                'أكمل قناة التوزيع والرقم الداخلي ورابط التحديث الرسمي قبل تفعيل الإصدار.',
            );
        }

        $version->is_active = !$version->is_active;
        $version->save();

        return redirect()->back()->with('success', 'تم تغيير الحالة بنجاح');
    }

    /**
     * Keep the dashboard contract aligned with the native stores: Android is
     * ordered by versionCode and iOS by CFBundleVersion (build number).
     */
    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $platform = (string) $request->input('platform');
        $channel = (string) $request->input('distribution_channel');
        $requiresUrl = $request->boolean('is_active') || $request->boolean('is_force_update');

        $data = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'distribution_channel' => $platform === 'ios'
                ? ['required', Rule::in(['appstore'])]
                : ['required', Rule::in(['play', 'direct'])],
            'version_name' => ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            'version_code' => [
                Rule::requiredIf($platform === 'android'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'version_code')
                    ->where(fn ($query) => $query
                        ->where('platform', 'android')
                        ->where('distribution_channel', $channel))
                    ->ignore($ignoreId),
            ],
            'build_number' => [
                Rule::requiredIf($platform === 'ios'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'build_number')
                    ->where(fn ($query) => $query
                        ->where('platform', 'ios')
                        ->where('distribution_channel', $channel))
                    ->ignore($ignoreId),
            ],
            'is_force_update' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'update_message_ar' => ['nullable', 'string', 'max:500'],
            'update_message_en' => ['nullable', 'string', 'max:500'],
            'download_url' => [
                Rule::requiredIf($requiresUrl),
                'nullable',
                'string',
                'max:2048',
                'url',
                function (string $attribute, $value, $fail) use ($channel): void {
                    if (!$this->isAllowedDownloadUrl($channel, $value)) {
                        $fail('استخدم رابط HTTPS رسميًا للمتجر أو لنطاق ركن فقط.');
                    }
                },
            ],
            'release_notes_ar' => ['nullable', 'string', 'max:5000'],
            'release_notes_en' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['is_force_update'] = $request->boolean('is_force_update');
        $data['is_active'] = $request->boolean('is_active');
        $data['version_code'] = $platform === 'android' ? (int) $data['version_code'] : null;
        $data['build_number'] = $platform === 'ios' ? (int) $data['build_number'] : null;

        return $data;
    }

    private function isActivatable(AppVersion $version): bool
    {
        $channel = (string) $version->distribution_channel;
        $hasIdentifier = $version->platform === 'android'
            ? in_array($channel, ['play', 'direct'], true) && (int) $version->version_code > 0
            : $channel === 'appstore' && (int) $version->build_number > 0;

        return $hasIdentifier
            && $this->isAllowedDownloadUrl($channel, $version->download_url);
    }

    private function isAllowedDownloadUrl(string $channel, $value): bool
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = match ($channel) {
            'play' => ['play.google.com'],
            'appstore' => ['apps.apple.com'],
            'direct' => ['rokn.app', 'www.rokn.app', 'rokn.com', 'www.rokn.com'],
            default => [],
        };

        return (bool) $parts
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && in_array($host, $allowedHosts, true);
    }
}
