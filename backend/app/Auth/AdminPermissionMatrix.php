<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The dashboard role matrix is deliberately an allow-list.
 *
 * Administrators retain full dashboard access. Moderators only receive the
 * named educational workflows below; a newly-added or unnamed route is denied
 * until it is reviewed and explicitly added here.
 */
final class AdminPermissionMatrix
{
    /** @var array<string, list<string>> */
    private const MODERATOR_RULES = [
        'admin.dashboard' => ['GET'],
        'admin.mfa.setup' => ['GET'],
        'admin.mfa.setup.confirm' => ['POST'],
        'admin.mfa.challenge' => ['GET'],
        'admin.mfa.challenge.verify' => ['POST'],
        'admin.mfa.backup-codes' => ['GET'],

        // Full course authoring, including pricing tiers and AI contracts.
        'admin.courses.index' => ['GET'],
        'admin.courses.show' => ['GET'],
        'admin.courses.create' => ['GET'],
        'admin.courses.store' => ['POST'],
        'admin.courses.edit' => ['GET'],
        'admin.courses.update' => ['PUT', 'PATCH'],
        'admin.courses.media-health.probe' => ['POST'],

        // Educational content authoring and review workflows.
        'admin.courses.sections.index' => ['GET'],
        'admin.courses.sections.create' => ['GET'],
        'admin.courses.sections.store' => ['POST'],
        'admin.courses.sections.show' => ['GET'],
        'admin.courses.sections.edit' => ['GET'],
        'admin.courses.sections.update' => ['PUT', 'PATCH'],
        'admin.courses.sections.destroy' => ['DELETE'],
        'admin.courses.sections.reorder' => ['POST'],
        'admin.courses.sections.video-uploads.store' => ['POST'],
        'admin.courses.sections.video-uploads.renew' => ['POST'],
        'admin.courses.modules.create' => ['GET'],
        'admin.courses.modules.store' => ['POST'],
        'admin.courses.modules.edit' => ['GET'],
        'admin.courses.modules.update' => ['PUT', 'PATCH'],
        'admin.courses.modules.destroy' => ['DELETE'],
        'admin.courses.modules.reorder' => ['POST'],
        'admin.courses.pdfs.index' => ['GET'],
        'admin.courses.pdfs.create' => ['GET'],
        'admin.courses.pdfs.store' => ['POST'],
        'admin.courses.pdfs.edit' => ['GET'],
        'admin.courses.pdfs.update' => ['PUT', 'PATCH'],
        'admin.courses.pdfs.destroy' => ['DELETE'],
        'admin.courses.pdfs.reorder' => ['POST'],
        'admin.courses.pdfs.toggle-status' => ['POST'],
        'admin.courses.pdfs.preview' => ['GET'],
        'admin.attachments.store' => ['POST'],
        'admin.attachments.destroy' => ['DELETE'],
        'admin.quizzes.index' => ['GET'],
        'admin.quizzes.create' => ['GET'],
        'admin.quizzes.store' => ['POST'],
        'admin.quizzes.edit' => ['GET'],
        'admin.quizzes.update' => ['PUT', 'PATCH'],
        'admin.quizzes.destroy' => ['DELETE'],
        'admin.quizzes.copy' => ['POST'],
        'admin.questions.index' => ['GET'],
        'admin.questions.create' => ['GET'],
        'admin.questions.store' => ['POST'],
        'admin.questions.show' => ['GET'],
        'admin.questions.edit' => ['GET'],
        'admin.questions.update' => ['PUT', 'PATCH'],
        'admin.questions.destroy' => ['DELETE'],
        'admin.lessons.index' => ['GET'],
        'admin.lessons.create' => ['GET'],
        'admin.lessons.show' => ['GET'],
        'admin.lessons.edit' => ['GET'],

        // content.review — inspect learner work and record the human decision.
        // Financial dashboards and provider operations remain administrator-only.
        'admin.project-submissions.index' => ['GET'],
        'admin.project-submissions.show' => ['GET'],
        'admin.project-submissions.download' => ['GET'],
        'admin.project-submissions.attachments.download' => ['GET'],
        'admin.project-submissions.pass' => ['POST'],
        'admin.project-submissions.reject' => ['POST'],

        // Teachers and curriculum reference data belong to course operations.
        'admin.teachers.index' => ['GET'],
        'admin.teachers.create' => ['GET'],
        'admin.teachers.store' => ['POST'],
        'admin.teachers.show' => ['GET'],
        'admin.teachers.edit' => ['GET'],
        'admin.teachers.update' => ['PUT', 'PATCH'],
        'admin.teachers.deactive' => ['PATCH'],
        'admin.categories.index' => ['GET'],
        'admin.grades.index' => ['GET'],
        'admin.grades.courses' => ['GET'],
        'admin.classifications.index' => ['GET'],
        'admin.classifications.create' => ['GET'],
        'admin.classifications.store' => ['POST'],
        'admin.classifications.edit' => ['GET'],
        'admin.classifications.update' => ['PUT', 'PATCH'],
        'admin.classifications.destroy' => ['DELETE'],
        'admin.paths.index' => ['GET'],
        'admin.paths.create' => ['GET'],
        'admin.paths.store' => ['POST'],
        'admin.paths.edit' => ['GET'],
        'admin.paths.update' => ['PUT', 'PATCH'],
        'admin.paths.destroy' => ['DELETE'],
        'admin.levels.index' => ['GET'],
        'admin.levels.create' => ['GET'],
        'admin.levels.store' => ['POST'],
        'admin.levels.edit' => ['GET'],
        'admin.levels.update' => ['PUT', 'PATCH'],
        'admin.levels.destroy' => ['DELETE'],
    ];

    public function allows(?string $role, ?string $routeName, string $method): bool
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'admin') {
            return true;
        }

        if ($role !== 'moderator' || blank($routeName)) {
            return false;
        }

        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $methods = self::MODERATOR_RULES[$routeName] ?? null;

        return is_array($methods) && in_array($method, $methods, true);
    }

    /** @return array<string, list<string>> */
    public function moderatorRules(): array
    {
        return self::MODERATOR_RULES;
    }
}
