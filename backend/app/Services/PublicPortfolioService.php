<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\CertificateResource;
use App\Http\Resources\PortfolioItemResource;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublicPortfolioService
{
    public function find(string $slug, ?string $highlightCertificate = null): ?array
    {
        $user = User::query()->where('portfolio_slug', $slug)->first();
        if (!$user && preg_match('/^student-([1-9][0-9]*)$/', $slug, $matches) === 1) {
            // Certificates issued to accounts created before portfolio slugs
            // existed use this deterministic fallback. Resolve only rows that
            // still have no explicit slug so a learner-chosen URL can never be
            // shadowed by the fallback form.
            $user = User::query()
                ->whereKey((int) $matches[1])
                ->whereNull('portfolio_slug')
                ->first();
        }
        if (!$user) {
            return null;
        }

        $isPublic = (bool) $user->portfolio_is_public;
        if ($highlightCertificate) {
            $revoked = $this->revokedVerification(
                (int) $user->id,
                (string) $highlightCertificate
            );
            if ($revoked) {
                // A revoked QR is a status-verification endpoint, not a
                // shortcut into the learner's otherwise public portfolio.
                return $this->limitedVerificationPayload($user, $slug, $revoked);
            }
        }
        $certificateAccess = null;
        $revokedVerification = null;
        if (!$isPublic) {
            // A certificate QR must remain verifiable even when its owner has
            // not published the full portfolio. The unguessable certificate
            // public ID grants a deliberately limited view; it never exposes
            // private projects, profile details, links or unrelated awards.
            if (!$highlightCertificate) {
                return null;
            }

            $certificateAccess = Certificate::query()
                ->where('user_id', $user->id)
                ->where('public_id', $highlightCertificate)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where('image_path', '!=', 'pending')
                ->with(['course', 'user'])
                ->first();
            if (!$certificateAccess) {
                $revokedVerification = $this->revokedVerification(
                    (int) $user->id,
                    (string) $highlightCertificate
                );
                if (!$revokedVerification) {
                    return null;
                }
            }
        }

        $items = $isPublic
            ? $user->portfolioItems()
                ->where('is_public', true)
                ->with(['mediaFiles', 'course'])
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->latest('id')
                ->get()
            : collect();
        $certificates = $revokedVerification && !$isPublic
            ? collect()
            : Certificate::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where('image_path', '!=', 'pending')
                ->when(!$isPublic, fn ($query) => $query->whereKey($certificateAccess->id))
                ->with(['course', 'user'])
                ->latest('generated_at')
                ->get();
        $highlighted = $highlightCertificate
            ? $certificates->first(fn ($certificate) =>
                $certificate->public_id !== null
                && hash_equals((string) $certificate->public_id, $highlightCertificate)
            )
            : null;
        if (!$highlighted && $highlightCertificate) {
            $revokedVerification ??= $this->revokedVerification(
                (int) $user->id,
                (string) $highlightCertificate
            );
        }

        // A badge is public only when the originating course explicitly opts in
        // and belongs to the professional/freelance tracks.
        $badges = $isPublic
            ? DB::table('user_level')
                ->join('levels', 'levels.id', '=', 'user_level.level_id')
                ->join('courses', 'courses.id', '=', 'user_level.course_id')
                ->where('user_level.user_id', $user->id)
                ->where('courses.awards_badge', true)
                ->whereIn('courses.badge_track', ['professional', 'freelance'])
                ->orderByDesc('user_level.earned_at')
                ->get([
                    'user_level.id as award_id', 'levels.id as level_id',
                    'levels.name_ar', 'levels.name_en', 'levels.badge_image', 'levels.order',
                    'courses.id as course_id', 'courses.name_ar as course_name_ar',
                    'courses.name_en as course_name_en', 'courses.badge_track',
                    'user_level.earned_at',
                ])
                ->map(function ($badge) {
                $path = (string) ($badge->badge_image ?? '');
                if ($path !== '' && filter_var($path, FILTER_VALIDATE_URL)) {
                    $badge->badge_image = $path;
                } elseif ($path !== '' && str_starts_with(ltrim($path, '/'), 'assets/')) {
                    $badge->badge_image = asset(ltrim($path, '/'));
                } elseif ($path !== '') {
                    $badge->badge_image = asset('storage/' . ltrim($path, '/'));
                } else {
                    $fallback = (int) $badge->order <= 1
                        ? 'junior.png'
                        : ((int) $badge->order === 2 ? 'mid-level.png' : 'senior.png');
                    $badge->badge_image = asset('assets/img/badges/' . $fallback);
                }

                unset($badge->order);
                return $badge;
                })
            : collect();

        return [
            'profile' => [
                'name' => $user->name,
                'headline' => $isPublic ? ($user->portfolio_headline ?: $user->job_title) : null,
                'bio' => $isPublic ? $user->bio : null,
                'location' => $isPublic ? $user->portfolio_location : null,
                'image_url' => $isPublic ? $user->profile_image_url : null,
                'skills' => $isPublic ? ($user->portfolio_skills ?? []) : [],
                // Sanitize again at the public read boundary so legacy rows
                // written before HTTPS-only validation can never reach href.
                'links' => $isPublic
                    ? collect($user->portfolio_links ?? [])
                        ->map(function ($link): ?array {
                            if (!is_array($link)) {
                                return null;
                            }

                            $safeUrl = SafeExternalUrl::sanitize($link['url'] ?? null);
                            if (!$safeUrl) {
                                return null;
                            }

                            return [
                                'label' => (string) ($link['label'] ?? ''),
                                'url' => $safeUrl,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all()
                    : [],
                'slug' => $slug,
                'public_url' => route('portfolio.public', ['slug' => $slug]),
                'is_public' => $isPublic,
            ],
            'projects' => PortfolioItemResource::collection($items)->resolve(),
            'certificates' => CertificateResource::collection($certificates)->resolve(),
            'highlighted_certificate' => $highlighted
                ? (new CertificateResource($highlighted))->resolve()
                : $revokedVerification,
            'badges' => $badges,
            'is_limited_certificate_view' => !$isPublic,
        ];
    }

    /**
     * A revoked certificate QR remains useful only as a minimal status check.
     * It deliberately exposes no artifact URL, projects, profile fields,
     * unrelated certificates or badges.
     */
    private function revokedVerification(int $userId, string $publicId): ?array
    {
        if ($publicId === '') {
            return null;
        }

        $certificate = Certificate::query()
            ->where('user_id', $userId)
            ->where('public_id', $publicId)
            ->where('status', 'revoked')
            ->whereNotNull('revoked_at')
            ->where('image_path', '!=', 'pending')
            ->with('course:id,name_ar,name_en')
            ->first(['id', 'public_id', 'user_id', 'course_id', 'status', 'verification_level', 'generated_at', 'revoked_at']);
        if (!$certificate) {
            return null;
        }

        return [
            'id' => null,
            'certificate_id' => null,
            'public_id' => (string) $certificate->public_id,
            'certificate_url' => '',
            'portfolio_url' => '',
            'status' => 'revoked',
            'verification_level' => $certificate->verification_level ?? 'completion',
            'verification_label' => 'Certificate revoked',
            'generated_at' => $certificate->generated_at?->format('c'),
            'revoked_at' => $certificate->revoked_at?->format('c'),
            'course' => [
                'id' => $certificate->course?->id,
                'name' => $certificate->course?->name_ar ?: $certificate->course?->name_en,
                'image' => null,
            ],
        ];
    }

    /** @param array<string,mixed> $verification */
    private function limitedVerificationPayload(
        User $user,
        string $slug,
        array $verification
    ): array {
        return [
            'profile' => [
                // The certificate holder name is part of verification. No
                // biography, avatar, links, location or skills are exposed.
                'name' => $user->name,
                'headline' => null,
                'bio' => null,
                'location' => null,
                'image_url' => null,
                'skills' => [],
                'links' => [],
                'slug' => $slug,
                'public_url' => route('portfolio.public', ['slug' => $slug]),
                'is_public' => false,
            ],
            'projects' => [],
            'certificates' => [],
            'highlighted_certificate' => $verification,
            'badges' => [],
            'is_limited_certificate_view' => true,
        ];
    }
}
