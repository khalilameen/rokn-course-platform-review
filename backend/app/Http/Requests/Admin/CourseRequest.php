<?php

namespace App\Http\Requests\Admin;

use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $singleLine = [
            'name_ar', 'name_en', 'attachment_prompt_title',
            'attachment_prompt_button_text', 'catalog_badge_ar',
            'catalog_badge_en', 'search_keywords_ar', 'search_keywords_en',
        ];
        $multiline = [
            'description_ar', 'description_en', 'chat_ai_prompt',
            'attachment_prompt_body',
        ];
        $normalized = [];
        foreach ($singleLine as $field) {
            if ($this->has($field)) {
                $normalized[$field] = UnicodeText::clean($this->input($field), false);
            }
        }
        foreach ($multiline as $field) {
            if ($this->has($field)) {
                $normalized[$field] = UnicodeText::clean($this->input($field));
            }
        }
        $plans = $this->input('access_plans');
        if (is_array($plans)) {
            foreach (['basic', 'guided', 'mentor'] as $code) {
                if (!is_array($plans[$code] ?? null)) continue;
                foreach (['name_ar', 'name_en'] as $field) {
                    if (array_key_exists($field, $plans[$code])) {
                        $plans[$code][$field] = UnicodeText::clean(
                            $plans[$code][$field],
                            false
                        );
                    }
                }
            }
            $normalized['access_plans'] = $plans;
        }
        if ($normalized !== []) $this->merge($normalized);
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name_ar' => 'required|string|min:3|max:255',
            'authoring_version' => [$this->isMethod('patch') || $this->isMethod('put') ? 'required' : 'nullable', 'integer', 'min:1'],
            'authoring_request_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
            'name_en' => 'nullable|string|max:255',
            'description_ar' => 'nullable|string|max:6000',
            'description_en' => 'nullable|string|max:6000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:6144|dimensions:min_width=640,min_height=360,max_width=5000,max_height=5000',
            'ai_model_type' => [
                'nullable',
                'string',
                'max:190',
                Rule::in(array_values(array_filter(config('openrouter.allowed_models', [])))),
            ],
            'temperature' => 'nullable|numeric|min:0|max:2',
            'tokens_number' => 'nullable|integer|min:1|max:' . max(1, (int) config('openrouter.max_tokens', 420)),
            'chat_ai_prompt' => 'nullable|string|max:1200',
            'ai_chat_enabled' => 'nullable|boolean',
            'chat_attachments_enabled' => 'nullable|boolean',
            'chat_attachment_max_files' => 'nullable|integer|min:1|max:5',
            'grant_chat_attachments_to_current_enrollments' => 'nullable|boolean',
            'grant_project_followup_attachments_to_current_enrollments' => 'nullable|boolean',
            'attachment_prompt_enabled' => 'nullable|boolean',
            'attachment_prompt_at_seconds' => 'required_if:attachment_prompt_enabled,1|nullable|integer|min:0|max:3600',
            'attachment_prompt_title' => 'nullable|string|max:120',
            'attachment_prompt_body' => 'nullable|string|max:500',
            'attachment_prompt_button_text' => 'nullable|string|max:80',
            'path_id' => 'nullable|exists:paths,id',
            'price' => 'nullable|integer|min:0|max:100000000',
            'is_coming_soon' => 'nullable|boolean',
            'is_catalog_visible' => 'nullable|boolean',
            'is_main_course' => 'nullable|boolean',
            // Older dashboard/API callers may omit this newly introduced field;
            // the database has a safe default of 100.  When it is supplied it
            // must still be a concrete, bounded integer (never null).
            'home_sort_order' => 'sometimes|required|integer|min:0|max:10000',
            'catalog_badge_ar' => 'nullable|string|max:40',
            'catalog_badge_en' => 'nullable|string|max:40',
            'catalog_badge_tone' => ['nullable', Rule::in(['blue', 'green', 'gold', 'neutral'])],
            'search_keywords_ar' => 'nullable|string|max:2000',
            'search_keywords_en' => 'nullable|string|max:2000',
            'level_id' => 'nullable|required_if:awards_badge,1|exists:levels,id',
            'awards_badge' => 'nullable|boolean',
            'badge_track' => 'nullable|required_if:awards_badge,1|in:professional,freelance',
            'certificate_text_template_key' => [
                'sometimes',
                'required',
                Rule::in(array_keys((array) config('certificate.text_templates', []))),
            ],
            'classification_ids' => 'nullable|array|max:12',
            'classification_ids.*' => 'integer|distinct|exists:classifications,id',
            'teacher_ids' => 'nullable|array|max:10',
            'teacher_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['teacher', 'admin'])),
            ],
            'access_plans' => 'nullable|array:basic,guided,mentor',
            'access_plans.basic' => 'required_with:access_plans|array',
            'access_plans.guided' => 'required_with:access_plans|array',
            'access_plans.mentor' => 'required_with:access_plans|array',
            'access_plans.*' => 'array',
            'access_plans.*.name_ar' => 'required_with:access_plans|string|max:120',
            'access_plans.*.name_en' => 'nullable|string|max:120',
            'access_plans.*.price_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.minimum_paid_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.is_active' => 'nullable|boolean',
            'access_plans.*.chat_enabled' => 'nullable|boolean',
            'access_plans.*.chat_message_limit' => 'nullable|integer|min:0|max:100000',
            'access_plans.*.chat_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.chat_attachments_enabled' => 'nullable|boolean',
            'access_plans.*.chat_attachment_max_files' => 'nullable|integer|min:0|max:5',
            'access_plans.*.project_followup_attachments_enabled' => 'nullable|boolean',
            'access_plans.*.project_followup_attachment_max_files' => 'nullable|integer|min:0|max:5',
            'access_plans.*.ai_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.request_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.project_feedback_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.project_feedback_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.project_feedback_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.project_followup_message_limit' => 'nullable|integer|min:0|max:100000',
            'access_plans.*.project_followup_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.project_followup_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.project_followup_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.max_output_tokens' => 'nullable|integer|min:80|max:' . max(80, (int) config('openrouter.max_tokens', 500)),
            'access_plans.*.model_override' => [
                'nullable', 'string', 'max:190',
                Rule::in(array_values(array_filter(config('openrouter.allowed_models', [])))),
            ],
            'access_plans.*.project_feedback_level' => ['nullable', Rule::in(['pass_only', 'report', 'enhanced'])],
            'access_plans.*.project_output_enabled' => 'nullable|boolean',
            'access_plans.*.certificate_enabled' => 'nullable|boolean',
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $plans = $this->input('access_plans');
            if (!is_array($plans)) return;
            $basic = (int) data_get($plans, 'basic.price_coins', 0);
            $guided = (int) data_get($plans, 'guided.price_coins', 0);
            $mentor = (int) data_get($plans, 'mentor.price_coins', 0);
            if ($guided < $basic || $mentor < $guided) {
                $validator->errors()->add(
                    'access_plans',
                    'سعر كل مستوى يجب أن يساوي أو يزيد عن المستوى الذي قبله.'
                );
            }

            foreach (['basic', 'guided', 'mentor'] as $code) {
                $row = is_array($plans[$code] ?? null) ? $plans[$code] : [];
                $maxOutput = max(80, (int) ($row['max_output_tokens'] ?? 320));
                $priceCoins = max(0, (int) ($row['price_coins'] ?? 0));
                $minimumPaidCoins = max(0, (int) ($row['minimum_paid_coins'] ?? 0));
                $feedback = (string) ($row['project_feedback_level'] ?? 'pass_only');
                $hasVariableCost = !empty($row['chat_enabled'])
                    || in_array($feedback, ['report', 'enhanced'], true);
                if (
                    $minimumPaidCoins > $priceCoins
                    || ($hasVariableCost && $minimumPaidCoins <= 0)
                ) {
                    $validator->errors()->add(
                        "access_plans.{$code}.minimum_paid_coins",
                        'اكتب حدًا موجبًا للفئة المكلفة لا يزيد عن سعرها.'
                    );
                }

                if (!empty($row['chat_enabled'])) {
                    $budget = (float) ($row['ai_budget_usd'] ?? 0);
                    $reserve = (float) ($row['request_reserve_usd'] ?? 0);
                    if (
                        (int) ($row['chat_message_limit'] ?? 0) < 1
                        || (int) ($row['chat_token_budget'] ?? 0) < $maxOutput
                        || $budget <= 0
                        || $reserve <= 0
                        || $reserve > $budget
                    ) {
                        $validator->errors()->add(
                            "access_plans.{$code}",
                            'ميزانية المحادثة وحجز الطلب يجب أن يكونا موجبين ومتوافقين مع حد الرد.'
                        );
                    }
                }

                if (in_array($feedback, ['report', 'enhanced'], true)) {
                    $budget = (float) ($row['project_feedback_budget_usd'] ?? 0);
                    $reserve = (float) ($row['project_feedback_reserve_usd'] ?? 0);
                    if (
                        (int) ($row['project_feedback_token_budget'] ?? 0) < $maxOutput
                        || $budget <= 0
                        || $reserve <= 0
                        || $reserve > $budget
                    ) {
                        $validator->errors()->add(
                            "access_plans.{$code}",
                            'ميزانية تقرير المشروع وحجزه يجب أن يكونا موجبين ومتوافقين مع حد الرد.'
                        );
                    }
                }
                if (!empty($row['chat_attachments_enabled']) && (
                    empty($row['chat_enabled'])
                    || (int) ($row['chat_attachment_max_files'] ?? 0) < 1
                )) {
                    $validator->errors()->add(
                        "access_plans.{$code}.chat_attachments_enabled",
                        'المرفقات تحتاج تفعيل Rokn AI وتحديد عدد ملفات من 1 إلى 5.'
                    );
                }
                if (!empty($row['project_followup_attachments_enabled']) && (
                    $feedback !== 'enhanced'
                    || (int) ($row['project_followup_attachment_max_files'] ?? 0) < 1
                )) {
                    $validator->errors()->add(
                        "access_plans.{$code}.project_followup_attachments_enabled",
                        'مرفقات متابعة المشروع تحتاج فئة enhanced وعدد ملفات من 1 إلى 5'
                    );
                }

                if ($feedback === 'enhanced') {
                    $budget = (float) ($row['project_followup_budget_usd'] ?? 0);
                    $reserve = (float) ($row['project_followup_reserve_usd'] ?? 0);
                    if (
                        (int) ($row['project_followup_message_limit'] ?? 0) < 1
                        || (int) ($row['project_followup_token_budget'] ?? 0) < $maxOutput
                        || $budget <= 0
                        || $reserve <= 0
                        || $reserve > $budget
                    ) {
                        $validator->errors()->add(
                            "access_plans.{$code}",
                            'محادثة تقرير المشروع تحتاج عدد رسائل وميزانية وحجزًا متوافقًا مع حد الرد.'
                        );
                    }
                }
            }
        });
    }

}
