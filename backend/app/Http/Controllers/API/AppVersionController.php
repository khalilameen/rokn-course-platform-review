<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class AppVersionController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function checkVersion(Request $request): JsonResponse
    {
        $platform = (string) $request->input('platform');
        $rules = [
            'platform' => ['required', 'in:android,ios'],
            'version' => $platform === 'android'
                ? ['required', 'integer', 'min:1']
                : ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            // New iOS clients send the monotonic CFBundleVersion. Older clients
            // remain supported through the marketing-version fallback below.
            'build_number' => $platform === 'ios'
                ? ['nullable', 'integer', 'min:1']
                : ['prohibited'],
            'distribution_channel' => $platform === 'ios'
                ? ['nullable', Rule::in(['appstore'])]
                : ['nullable', Rule::in(['play', 'direct'])],
        ];
        $validated = $request->validate($rules);

        /** @var Collection<int, AppVersion> $versions */
        $versions = $this->activeVersions(
            (string) $validated['platform'],
            isset($validated['distribution_channel'])
                ? (string) $validated['distribution_channel']
                : null,
        );

        if ($versions->isEmpty()) {
            return $this->noUpdateResponse();
        }

        if ($validated['platform'] === 'android') {
            return $this->androidResponse($versions, (int) $validated['version']);
        }

        return $this->iosResponse(
            $versions,
            (string) $validated['version'],
            isset($validated['build_number']) ? (int) $validated['build_number'] : null,
        );
    }

    /** @param Collection<int, AppVersion> $versions */
    private function androidResponse(Collection $versions, int $currentCode): JsonResponse
    {
        /** @var AppVersion|null $latest */
        $latest = $versions
            ->filter(fn (AppVersion $version): bool => $version->version_code !== null)
            ->sortByDesc(fn (AppVersion $version): int => (int) $version->version_code)
            ->first();

        if (!$latest) {
            return $this->noUpdateResponse();
        }

        $updateRequired = (int) $latest->version_code > $currentCode;
        $forceUpdate = $updateRequired && $versions->contains(
            fn (AppVersion $version): bool =>
                $version->version_code !== null
                && (int) $version->version_code > $currentCode
                && $version->is_force_update,
        );

        return $this->versionResponse($latest, $updateRequired, $forceUpdate);
    }

    /** @param Collection<int, AppVersion> $versions */
    private function iosResponse(
        Collection $versions,
        string $currentVersion,
        ?int $currentBuild,
    ): JsonResponse {
        /** @var AppVersion $latest */
        $latest = $versions->sort(function (AppVersion $left, AppVersion $right): int {
            $leftBuild = $left->build_number;
            $rightBuild = $right->build_number;

            if ($leftBuild !== null && $rightBuild !== null && $leftBuild !== $rightBuild) {
                return (int) $rightBuild <=> (int) $leftBuild;
            }
            if (($leftBuild !== null) !== ($rightBuild !== null)) {
                return $leftBuild !== null ? -1 : 1;
            }

            return version_compare((string) $right->version_name, (string) $left->version_name);
        })->first();

        if ($currentBuild !== null && $latest->build_number !== null) {
            $updateRequired = (int) $latest->build_number > $currentBuild;
            $forceUpdate = $updateRequired && $versions->contains(
                fn (AppVersion $version): bool =>
                    $version->build_number !== null
                    && (int) $version->build_number > $currentBuild
                    && $version->is_force_update,
            );
        } else {
            // Backwards compatibility for clients released before build_number
            // was added to the request contract.
            $updateRequired = version_compare((string) $latest->version_name, $currentVersion, '>');
            $forceUpdate = $updateRequired && $versions->contains(
                fn (AppVersion $version): bool =>
                    version_compare((string) $version->version_name, $currentVersion, '>')
                    && $version->is_force_update,
            );
        }

        return $this->versionResponse($latest, $updateRequired, $forceUpdate);
    }

    private function versionResponse(
        AppVersion $latest,
        bool $updateRequired,
        bool $forceUpdate,
    ): JsonResponse {
        return $this->responses->success(
            [
                'update_required' => $updateRequired,
                'is_force_update' => $forceUpdate,
                'latest_version' => $latest->version_name,
                'latest_version_code' => $latest->version_code,
                'latest_build_number' => $latest->build_number,
                'distribution_channel' => $latest->distribution_channel,
                'update_message' => app()->getLocale() === 'ar'
                    ? $latest->update_message_ar
                    : ($latest->update_message_en ?: $latest->update_message_ar),
                'download_url' => $latest->download_url,
                'release_notes' => app()->getLocale() === 'ar'
                    ? $latest->release_notes_ar
                    : ($latest->release_notes_en ?: $latest->release_notes_ar),
            ],
            'App version policy retrieved successfully'
        );
    }

    private function noUpdateResponse(): JsonResponse
    {
        return $this->responses->success(
            [
                'update_required' => false,
                'is_force_update' => false,
            ],
            'The app is up to date'
        );
    }

    /** @return Collection<int, AppVersion> */
    private function activeVersions(string $platform, ?string $channel): Collection
    {
        $base = AppVersion::query()
            ->where('platform', $platform)
            ->where('is_active', true);

        if ($channel !== null) {
            $exact = (clone $base)
                ->where('distribution_channel', $channel)
                ->get();

            // Legacy rows are accepted only when this channel has no explicit
            // release. Rows for another channel are never considered.
            return $exact->isNotEmpty()
                ? $exact
                : (clone $base)->whereNull('distribution_channel')->get();
        }

        if ($platform === 'ios') {
            $appStore = (clone $base)
                ->where('distribution_channel', 'appstore')
                ->get();

            return $appStore->isNotEmpty()
                ? $appStore
                : (clone $base)->whereNull('distribution_channel')->get();
        }

        // An old Android client did not identify whether it came from Play or
        // the direct APK. Only an unclassified legacy release is safe here.
        return (clone $base)->whereNull('distribution_channel')->get();
    }
}
