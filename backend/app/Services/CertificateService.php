<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RoknPublicUrl;
use App\Support\StorageWriteOptions;
use App\Support\UnicodeText;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use ArPHP\I18N\Arabic;
use Carbon\CarbonImmutable;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CertificateService
{
    public function __construct(
        private readonly FinancialProvenanceService $financialProvenance,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CertificateEligibilityService $eligibility
    ) {
    }

    /**
     * Generate (or retrieve existing) certificate for a user + course.
     */
    public function generate(
        User $user,
        Course $course,
        ?Project $project = null,
        ?string $requestedHolderName = null
    ): ?Certificate
    {
        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();
        $latestEnrollment = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest('id')
            ->first();

        // An issued credential is an immutable achievement snapshot. Reading
        // or rebuilding its artifact does not depend on a subscription that
        // may expire later. Financial voids remain authoritative through the
        // revocation state and the hold checked here before any recovery.
        if ($certificate) {
            if (
                ($certificate->status ?? 'active') === 'revoked'
                || ($latestEnrollment && $this->financialProvenance
                    ->enrollmentHasActiveHold($latestEnrollment, ['course']))
            ) {
                return null;
            }
        } else {
            $enrollment = CourseEnrollment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
            if (
                !$enrollment
                || $this->financialProvenance->enrollmentHasActiveHold($enrollment, ['course'])
                || !$this->courseAccess->hasCertificateAccess((int) $user->id, (int) $course->id)
            ) {
                return null;
            }
        }

        $verificationLevel = $this->verificationLevel($user, $course);
        // Allows rolling deployments: old web workers may run briefly before
        // this additive migration reaches every database connection.
        $supportsVerificationLevel = Schema::hasColumn('certificates', 'verification_level');
        $supportsIdentitySnapshots = Schema::hasColumns('certificates', [
            'holder_name',
            'course_name',
        ]);
        $supportsTextSnapshots = Schema::hasColumns('certificates', [
            'certificate_text_template_key',
            'certificate_text',
        ]);
        $textTemplate = $this->textTemplateForCourse($course);
        $requestedHolderName = UnicodeText::limit(
            UnicodeText::clean($requestedHolderName, false),
            120
        );
        // Creating a credential is an explicit learner action because this
        // text becomes an immutable identity snapshot. Recovery of an old
        // pending row remains automatic and uses its stored snapshot.
        if (!$certificate && UnicodeText::graphemeLength($requestedHolderName) < 2) {
            return null;
        }

        // Revocation is terminal. A missing artifact or a retry must never
        // turn a revoked credential active again.
        if ($certificate && ($certificate->status ?? 'active') === 'revoked') {
            return null;
        }

        // Eligibility creates a credential; it does not expire one already
        // issued. A pending or lost artifact can therefore be rebuilt from its
        // immutable row even if progress tables are later archived.
        if (!$certificate && !$this->eligibility->for($user, $course)['available']) {
            return null;
        }

        if ($certificate && $supportsIdentitySnapshots) {
            $this->fillMissingIdentitySnapshots($certificate, $user, $course);
        }
        if ($certificate && $this->artifactExists($certificate)) {
            if (Schema::hasColumn('certificates', 'artifact_checked_at')) {
                $certificate->forceFill(['artifact_checked_at' => now()])->save();
            }
            return $certificate;
        }

        if (!$certificate) {
            // Create the DB record first so the public credential ID is stable
            // across retries. The user row serializes this with account
            // deletion, so an old request cannot create a credential after
            // the account has been withdrawn.
            try {
                $createAttributes = [
                    'public_id'    => (string) Str::uuid(),
                    'user_id'      => $user->id,
                    'course_id'    => $course->id,
                    'project_id'   => $project?->id,
                    'image_path'   => 'pending',
                    'generated_at' => now(),
                    'status'       => 'active',
                ];
                if ($supportsIdentitySnapshots) {
                    $createAttributes['holder_name'] = $requestedHolderName;
                    $createAttributes['course_name'] = $this->courseName($course);
                }
                if ($supportsVerificationLevel) {
                    $createAttributes['verification_level'] = $verificationLevel;
                }
                if ($supportsTextSnapshots) {
                    $createAttributes['certificate_text_template_key'] = $textTemplate['key'];
                    $createAttributes['certificate_text'] = $textTemplate['text'];
                }
                $certificate = DB::transaction(function () use (
                    $user,
                    $course,
                    $createAttributes
                ): ?Certificate {
                    $lockedUser = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->first();
                    if (!$lockedUser) {
                        return null;
                    }

                    return Certificate::query()
                        ->where('user_id', $lockedUser->id)
                        ->where('course_id', $course->id)
                        ->first()
                        ?: Certificate::create($createAttributes);
                }, 3);
                if (!$certificate) {
                    return null;
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $certificate = Certificate::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->first();
                if (!$certificate) {
                    throw $e;
                }
                if (($certificate->status ?? 'active') === 'revoked') {
                    return null;
                }
            }
        }

        if (!$certificate->public_id) {
            Certificate::query()
                ->whereKey($certificate->id)
                ->whereNull('public_id')
                ->update(['public_id' => (string) Str::uuid()]);
            $certificate->refresh();
        }

        if ($supportsIdentitySnapshots) {
            $this->fillMissingIdentitySnapshots($certificate, $user, $course);
        }
        if ($supportsTextSnapshots) {
            $this->fillMissingTextSnapshot($certificate, $textTemplate);
        }

        $leaseId = (string) Str::uuid();
        $certificate = DB::transaction(function () use ($certificate, $leaseId): ?Certificate {
            $locked = Certificate::query()->lockForUpdate()->find($certificate->id);
            if (
                !$locked
                || ($locked->status ?? 'active') !== 'active'
                || $locked->revoked_at !== null
                || !User::query()->whereKey($locked->user_id)->exists()
            ) {
                return null;
            }
            if (
                trim((string) $locked->generation_lease_id) !== ''
                && $locked->updated_at?->isAfter(now()->subMinutes(5))
            ) {
                return null;
            }
            $locked->forceFill(['generation_lease_id' => $leaseId])->save();
            return $locked->fresh();
        }, 3);
        if (!$certificate) {
            return null;
        }

        // The issue date is credential history, not the time of an artifact
        // recovery. Retrying a pending or lost image keeps the original date.
        $previousPath = trim((string) $certificate->image_path);
        $generatedAt = $certificate->generated_at ?? now();
        $filePath = $this->createCertificateImage(
            $user,
            $course,
            $certificate,
            $generatedAt,
            $leaseId
        );

        if (!$filePath) {
            // Keep the pending row as the durable recovery marker. The queued
            // listener or an authenticated recovery request can safely retry.
            Certificate::query()
                ->whereKey($certificate->id)
                ->where('generation_lease_id', $leaseId)
                ->update(['generation_lease_id' => null]);
            return null;
        }

        $updateAttributes = [
            'image_path' => $filePath,
            'status' => 'active',
            'generation_lease_id' => null,
        ];
        if (Schema::hasColumn('certificates', 'recovery_attempts')) {
            $updateAttributes += [
                'recovery_attempts' => 0,
                'recovery_next_attempt_at' => null,
                'recovery_failed_at' => null,
                'recovery_failure_code' => null,
            ];
        }
        if (Schema::hasColumn('certificates', 'artifact_checked_at')) {
            $updateAttributes['artifact_checked_at'] = now();
        }
        $committed = Certificate::query()
            ->whereKey($certificate->id)
            ->where('generation_lease_id', $leaseId)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->whereNull('revoked_at')
            ->whereHas('user')
            ->update($updateAttributes);
        if ($committed !== 1) {
            $this->deleteCertificateArtifact($filePath);
            return null;
        }
        $certificate->refresh();
        if (
            $previousPath !== ''
            && $previousPath !== 'pending'
            && $previousPath !== $filePath
        ) {
            $this->deleteCertificateArtifact($previousPath);
        }

        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_CERTIFICATE_READY,
            'شهادتك جاهزة',
            'Your certificate is ready',
            'أكملت الكورس وأصبحت شهادتك جاهزة',
            'You completed the course and your certificate is ready.',
            'rokn://profile',
            Course::class,
            (int) $course->id,
            'certificate-ready:' . $certificate->id,
            ['course' => (string) ($course->name_ar ?: $course->name_en)]
        );

        return $certificate;
    }

    private function verificationLevel(
        User $user,
        Course $course
    ): string
    {
        // A course may contain several graduation projects. The certificate
        // label describes the strongest verified evidence in that course, not
        // whichever project happened to be returned first by a legacy query.
        $humanReviewed = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->where('review_source', 'admin_manual')
            ->whereHas('project', function ($projects) use ($course): void {
                $projects->where('is_graduation_project', true)
                    ->whereHas('section', fn ($sections) =>
                        $sections->where('course_id', $course->id)
                    );
            })
            ->exists();

        return $humanReviewed ? 'reviewed_project' : 'completion';
    }

    /* ------------------------------------------------------------------
     * Image generation
     * ----------------------------------------------------------------*/

    private function createCertificateImage(
        User $user,
        Course $course,
        Certificate $certificate,
        \DateTimeInterface $generatedAt,
        string $generationLeaseId
    ): ?string
    {
        try {
            $cfg       = config('certificate');
            $positions = $cfg['text_positions'];
            $fontPath  = $cfg['font_regular'];

            $templatePath = $cfg['template_path'];
            if (!file_exists($templatePath)) {
                report(new \RuntimeException("Certificate template not found at: {$templatePath}"));
                return null;
            }

            // Load the template
            $img    = Image::make($templatePath);
            $width  = $img->width();
            $height = $img->height();

            // ----- 1. Student name -----
            $studentName = UnicodeText::clean($certificate->holder_name, false)
                ?: $this->holderName($user);
            $studentName = $this->shapeIfArabic($studentName);

            $pos = $positions['name'];
            $img->text($studentName, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align('center');
                $font->valign('middle');
            });

            // ----- 2. Achievement wording -----
            // This comes from the immutable certificate snapshot, never from
            // the course's current selection or the live config entry.
            $achievementText = UnicodeText::clean($certificate->certificate_text, false);
            $achievementPosition = $positions['achievement'] ?? null;
            if ($achievementText !== '' && is_array($achievementPosition)) {
                $achievementText = $this->shapeIfArabic($achievementText);
                $img->text(
                    $achievementText,
                    (int) ($width * $achievementPosition['x']),
                    (int) ($height * $achievementPosition['y']),
                    function ($font) use ($fontPath, $achievementPosition): void {
                        $font->file($fontPath);
                        $font->size($achievementPosition['size']);
                        $font->color($achievementPosition['color']);
                        $font->align('center');
                        $font->valign('middle');
                    }
                );
            }

            // ----- 3. Course name -----
            $courseName = UnicodeText::clean($certificate->course_name, false)
                ?: $this->courseName($course);
            if ($courseName) {
                $courseName = $this->shapeIfArabic($courseName);
                $pos = $positions['course'];
                $img->text($courseName, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                    $font->file($fontPath);
                    $font->size($pos['size']);
                    $font->color($pos['color']);
                    $font->align('center');
                    $font->valign('middle');
                });
            }

            // ----- 4. Certificate ID -----
            // The printed credential must match the public API and QR target;
            // database sequence IDs are implementation details, not credentials.
            $certIdText = (string) $certificate->public_id;
            $pos = $positions['cert_id'];
            $img->text($certIdText, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align('center');
                $font->valign('middle');
            });

            // ----- 5. Date -----
            $dateText = CarbonImmutable::instance($generatedAt)
                ->locale('ar')
                ->translatedFormat($cfg['date_format']);
            $dateText = strtr($dateText, [
                '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
            ]);
            $dateText = $this->shapeIfArabic($dateText);
            $pos = $positions['date'];
            $img->text($dateText, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align('center');
                $font->valign('middle');
            });

            // ----- 6. QR code -----
            $profileUrl = RoknPublicUrl::certificate((string) $certificate->public_id);
            $qrSize     = $positions['qr_code']['size'];
            $qrPng      = $this->generateQrCode($profileUrl, $qrSize);

            if ($qrPng) {
                $qrImage = Image::make($qrPng);
                // Position the QR so its centre aligns with the configured point
                $qrX = (int)($width  * $positions['qr_code']['x']) - (int)($qrImage->width()  / 2);
                $qrY = (int)($height * $positions['qr_code']['y']) - (int)($qrImage->height() / 2);
                $img->insert($qrImage, 'top-left', max(0, $qrX), max(0, $qrY));
            }

            // ----- Save -----
            // Public certificate images must not be enumerable by numeric user/course IDs.
            $filename = 'certificate_' . $certificate->public_id
                . '_' . str_replace('-', '', $generationLeaseId) . '.png';
            $storagePath = 'certificates/' . $filename;
            $disk = (string) config('certificate.disk', 'public');
            $encoded = (string) $img->encode('png', 95);
            app(StoredFileDeletionService::class)
                ->trackPotentialOrphan($disk, $storagePath, 60);
            $stored = Storage::disk($disk)->put(
                $storagePath,
                $encoded,
                StorageWriteOptions::forDisk($disk, 'private')
            );

            if (!$stored) {
                throw new \RuntimeException('Certificate artifact could not be stored.');
            }

            return $storagePath;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function artifactExists(Certificate $certificate): bool
    {
        return $certificate->hasStoredArtifact();
    }

    private function deleteCertificateArtifact(string $path): void
    {
        try {
            app(StoredFileDeletionService::class)->deleteOrQueue(
                (string) config('certificate.disk', 'public'),
                $path
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function fillMissingIdentitySnapshots(
        Certificate $certificate,
        User $user,
        Course $course
    ): void {
        $updates = [];
        if (trim((string) $certificate->holder_name) === '') {
            $updates['holder_name'] = $this->holderName($user);
        }
        if (trim((string) $certificate->course_name) === '') {
            $updates['course_name'] = $this->courseName($course);
        }
        if ($updates === []) {
            return;
        }

        // Conditional writes make concurrent recovery safe and ensure that a
        // later profile or course edit can never replace the issued identity.
        foreach ($updates as $column => $value) {
            Certificate::query()
                ->whereKey($certificate->id)
                ->where(function ($query) use ($column): void {
                    $query->whereNull($column)->orWhere($column, '');
                })
                ->update([$column => $value]);
        }
        $certificate->refresh();
    }

    /** @param array{key:string,text:string} $template */
    private function fillMissingTextSnapshot(
        Certificate $certificate,
        array $template
    ): void {
        DB::transaction(function () use ($certificate, $template): void {
            $locked = Certificate::query()->lockForUpdate()->find($certificate->id);
            if (!$locked) return;

            $key = trim((string) $locked->certificate_text_template_key);
            $text = trim((string) $locked->certificate_text);
            if ($key !== '' && $text !== '') return;

            // The two values are one immutable editorial snapshot. Fill them
            // together under the same row lock so parallel recovery cannot
            // combine a key from one course setting with another text. This
            // path runs only when no artifact exists yet, so an incomplete
            // pair has no prior printed claim to preserve. Repair the pair
            // from the one course snapshot captured by this generation call;
            // never derive half of it from mutable live config.
            Certificate::query()->whereKey($locked->id)->update([
                'certificate_text_template_key' => $template['key'],
                'certificate_text' => $template['text'],
            ]);
        }, 3);
        $certificate->refresh();
    }

    /** @return array{key:string,text:string} */
    private function textTemplateForCourse(Course $course): array
    {
        $templates = (array) config('certificate.text_templates', []);
        $defaultKey = trim((string) config(
            'certificate.default_text_template_key',
            'completion'
        ));
        if (!isset($templates[$defaultKey]) || !is_array($templates[$defaultKey])) {
            $defaultKey = 'completion';
        }

        $selectedKey = trim((string) $course->getRawOriginal(
            'certificate_text_template_key'
        ));
        if (!isset($templates[$selectedKey]) || !is_array($templates[$selectedKey])) {
            $selectedKey = $defaultKey;
        }

        $text = UnicodeText::limit(
            UnicodeText::clean(data_get($templates, $selectedKey.'.text'), false),
            255
        );
        if ($text === '') {
            $selectedKey = 'completion';
            $text = 'تقديرًا لإتمام متطلبات كورس';
        }

        return ['key' => $selectedKey, 'text' => $text];
    }

    private function holderName(User $user): string
    {
        return $this->firstText([
            $user->getRawOriginal('name_ar'),
            $user->getRawOriginal('name_en'),
            $user->getRawOriginal('name'),
        ], 'طالب ركن');
    }

    private function courseName(Course $course): string
    {
        return $this->firstText([
            $course->getRawOriginal('name_ar'),
            $course->getRawOriginal('name_en'),
        ], 'كورس ركن');
    }

    /** @param array<int, mixed> $values */
    private function firstText(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            $text = UnicodeText::clean($value, false);
            if ($text !== '') {
                return $text;
            }
        }

        return $fallback;
    }

    /* ------------------------------------------------------------------
     * QR code generation (endroid/qr-code)
     * ----------------------------------------------------------------*/

    private function generateQrCode(string $url, int $size = 100): ?string
    {
        try {
            $qrCode = new QrCode(
                data: $url,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 5,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255),
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            return $result->getString();
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    /* ------------------------------------------------------------------
     * Arabic text shaping
     * ----------------------------------------------------------------*/

    /**
     * If the text contains Arabic characters, apply glyph shaping so
     * that GD / imagettftext renders them correctly (joined, RTL).
     */
    private function shapeIfArabic(string $text): string
    {
        $text = UnicodeText::clean($text, false);
        if (!preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        $arabic   = new Arabic();
        $positions = $arabic->arIdentify($text);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $start  = $positions[$i - 1];
            $length = $positions[$i] - $start;
            $substr = substr($text, $start, $length);
            $shaped = $arabic->utf8Glyphs($substr);
            $text   = substr_replace($text, $shaped, $start, $length);
        }

        return $text;
    }
}
