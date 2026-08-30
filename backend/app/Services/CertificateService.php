<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use ArPHP\I18N\Arabic;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class CertificateService
{
    public function __construct(
        private readonly FinancialProvenanceService $financialProvenance,
        private readonly CourseChatAccessService $courseAccess
    ) {
    }

    /**
     * Generate (or retrieve existing) certificate for a user + course.
     */
    public function generate(User $user, Course $course, ?Project $project = null): ?Certificate
    {
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

        $verificationLevel = $this->verificationLevel($user, $project);
        // Allows rolling deployments: old web workers may run briefly before
        // this additive migration reaches every database connection.
        $supportsVerificationLevel = Schema::hasColumn('certificates', 'verification_level');
        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($certificate && $this->artifactExists($certificate)) {
            if (
                $supportsVerificationLevel
                && ($certificate->verification_level ?? 'completion') !== $verificationLevel
            ) {
                $certificate->forceFill(['verification_level' => $verificationLevel])->save();
            }
            return $certificate;
        }

        if (!$certificate) {
            // Create the DB record first so the public credential ID is stable
            // across retries. A unique constraint resolves concurrent jobs.
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
                if ($supportsVerificationLevel) {
                    $createAttributes['verification_level'] = $verificationLevel;
                }
                $certificate = Certificate::create($createAttributes);
            } catch (\Illuminate\Database\QueryException $e) {
                $certificate = Certificate::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->first();
                if (!$certificate) {
                    throw $e;
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

        $generatedAt = now();
        $filePath = $this->createCertificateImage(
            $user,
            $course,
            $certificate,
            $generatedAt
        );

        if (!$filePath) {
            // Keep the pending row as the durable recovery marker. The queued
            // listener or an authenticated recovery request can safely retry.
            return null;
        }

        $updateAttributes = [
            'image_path' => $filePath,
            'generated_at' => $generatedAt,
            'status' => 'active',
        ];
        if ($supportsVerificationLevel) {
            $updateAttributes['verification_level'] = $verificationLevel;
        }
        $certificate->update($updateAttributes);

        return $certificate;
    }

    private function verificationLevel(User $user, ?Project $project): string
    {
        if (!$project) {
            return 'completion';
        }

        $humanReviewed = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->where('review_source', 'admin_manual')
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
        \DateTimeInterface $generatedAt
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
            $studentName = $user->name
                ?? $user->name_ar
                ?? $user->name_en
                ?? 'Student';
            $studentName = $this->shapeIfArabic($studentName);

            $pos = $positions['name'];
            $img->text($studentName, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align('center');
                $font->valign('middle');
            });

            // ----- 2. Course name -----
            $courseName = $course->name
                ?? $course->name_ar
                ?? $course->name_en
                ?? '';
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

            // ----- 3. Certificate ID -----
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

            // ----- 4. Date -----
            $dateText = $generatedAt->format($cfg['date_format']);
            $pos = $positions['date'];
            $img->text($dateText, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align('center');
                $font->valign('middle');
            });

            // ----- 5. QR code -----
            $portfolioSlug = $user->portfolio_slug ?: ('student-' . $user->id);
            $profileUrl = route('portfolio.public', [
                'slug' => $portfolioSlug,
                'certificate' => $certificate->public_id,
            ]);
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
            $filename    = 'certificate_' . $certificate->public_id . '.png';
            $storagePath = 'certificates/' . $filename;
            $disk = (string) config('certificate.disk', 'public');
            $visibility = (string) config('certificate.visibility', 'public');
            $encoded = (string) $img->encode('png', 95);
            $stored = Storage::disk($disk)->put($storagePath, $encoded, [
                'visibility' => $visibility,
            ]);

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
        $path = trim((string) $certificate->image_path);
        if ($path === '' || $path === 'pending' || ($certificate->status ?? 'active') !== 'active') {
            return false;
        }

        try {
            return Storage::disk((string) config('certificate.disk', 'public'))->exists($path);
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
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
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
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
