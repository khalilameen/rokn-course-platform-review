            @if(in_array(strtolower((string) optional(auth()->user())->role), ['admin', 'moderator'], true))
            @php
                $accessPlansByCode = $course->accessPlans->keyBy('code');
                $planLabels = [
                    'basic' => ['التعلّم', 'الكورس والمشروعات والعبور من غير تكلفة ذكاء اصطناعي'],
                    'guided' => ['التعلّم بإرشاد', 'تقرير عن المشروع يصل داخل شات ركن من دون رد'],
                    'mentor' => ['التعلّم بمتابعة', 'تقرير عن المشروع ثم محادثة متابعة داخل شات ركن'],
                ];
            @endphp
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon"><i class="fa fa-layer-group"></i></div>
                    خطط فتح الكورس
                </h2>
                <div class="form-help course-editor__section-help">
                    السعر وما يفتحه كل اختيار هنا هو العقد الحقيقي للمشتريات الجديدة. الدولار والتوكنز حدود تكلفة داخلية ولا تظهر للطالب. المشتريات القديمة لا تتغير.
                </div>
                <div class="course-editor__plan-grid">
                    @foreach($planLabels as $code => [$label, $description])
                        @php
                            $plan = $accessPlansByCode->get($code);
                            $recommendedAiCoins = 0;
                        @endphp
                        <div class="course-editor__plan-card">
                            <div class="course-editor__plan-title">{{ $label }}</div>
                            <div class="course-editor__plan-description">{{ $description }}</div>
                            @if($plan && $code !== 'basic')
                                @php
                                    $planMaxCost = (float) $plan->ai_budget_usd
                                        + (float) $plan->project_feedback_budget_usd
                                        + (float) $plan->project_followup_budget_usd;
                                    $coinUsd = max(.000001, (float) config('course_plans.net_usd_per_paid_coin', .001));
                                    $costSafety = max(1, (float) config('course_plans.ai_cost_safety_multiplier', 2));
                                    $recommendedAiCoins = (int) (ceil((($planMaxCost * $costSafety) / $coinUsd) / 50) * 50);
                                @endphp
                                <div class="course-editor__plan-note">
                                    احتياطي AI المقترح فوق سعر المحتوى: <strong>{{ number_format($recommendedAiCoins) }} عملة</strong>
                                </div>
                            @endif
                            @if($plan && $planStats->has($code))
                                @php $stats = $planStats->get($code); @endphp
                                <div class="course-editor__plan-stats">
                                    <span>عمليات الشراء: <strong>{{ number_format($stats['sales_count']) }}</strong></span>
                                    <span>إجمالي العملات: <strong>{{ number_format($stats['total_coins']) }}</strong></span>
                                    <span>مدفوعة: <strong>{{ number_format($stats['paid_coins']) }}</strong></span>
                                    <span>مكافآت: <strong>{{ number_format($stats['reward_coins']) }}</strong></span>
                                    <span>أسئلة الشات: <strong>{{ number_format($stats['chat_requests']) }}</strong></span>
                                    <span>توكنز الشات: <strong>{{ number_format($stats['chat_tokens']) }}</strong></span>
                                    <span>تكلفة الشات: <strong>${{ number_format($stats['chat_cost_usd'], 6) }}</strong></span>
                                    <span>مراجعات المشاريع: <strong>{{ number_format($stats['project_requests']) }}</strong></span>
                                    <span>توكنز المراجعات: <strong>{{ number_format($stats['project_tokens']) }}</strong></span>
                                    <span>تكلفة المراجعات: <strong>${{ number_format($stats['project_cost_usd'], 6) }}</strong></span>
                                    <span>رسائل متابعة المشاريع: <strong>{{ number_format($stats['followup_requests']) }}</strong></span>
                                    <span>توكنز المتابعة: <strong>{{ number_format($stats['followup_tokens']) }}</strong></span>
                                    <span>تكلفة المتابعة: <strong>${{ number_format($stats['followup_cost_usd'], 6) }}</strong></span>
                                    <span class="course-editor__plan-stats-total">إجمالي تكلفة OpenRouter {{ $stats['estimated_cost_requests'] > 0 ? 'المسجلة' : 'المؤكدة' }}: <strong>${{ number_format($stats['chat_cost_usd'] + $stats['project_cost_usd'] + $stats['followup_cost_usd'], 6) }}</strong>@if($stats['estimated_cost_requests'] > 0) <small>تتضمن {{ number_format($stats['estimated_cost_requests']) }} تقديرًا</small>@endif</span>
                                </div>
                            @endif
                            <label class="form-label-modern">اسم الفئة الظاهر للطالب</label>
                            <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_ar]" value="{{ old("access_plans.$code.name_ar", $plan?->name_ar ?? $label) }}" required>
                            @if($enableEnglish)
                                <label class="form-label-modern">اسم الفئة بالإنجليزية</label>
                                <input class="form-control-modern" type="text" maxlength="120" name="access_plans[{{ $code }}][name_en]" value="{{ old("access_plans.$code.name_en", $plan?->name_en) }}">
                            @else
                                <input type="hidden" name="access_plans[{{ $code }}][name_en]" value="{{ $plan?->name_en }}">
                            @endif
                            <input type="hidden" name="access_plans[{{ $code }}][is_active]" value="0">
                            <label class="course-editor__inline-check course-editor__inline-check--spaced">
                                <input type="checkbox" name="access_plans[{{ $code }}][is_active]" value="1" {{ old("access_plans.$code.is_active", $plan?->is_active) ? 'checked' : '' }}>
                                متاحة للشراء
                            </label>
                            <label class="form-label-modern">السعر بعملات ركن</label>
                            <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][price_coins]" value="{{ old("access_plans.$code.price_coins", $plan?->price_coins ?? 0) }}" required>
                            <label class="form-label-modern">الحد الأدنى من العملات المدفوعة</label>
                            <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][minimum_paid_coins]" value="{{ old("access_plans.$code.minimum_paid_coins", $plan?->minimum_paid_coins ?? ($code === 'basic' ? 0 : ($recommendedAiCoins ?? 0))) }}" required>
                            <div class="form-help">
                                لا يمكن تغطية هذا الجزء بعملات الهدايا أو المهام. اجعله صفرًا فقط للفئة التي لا تستهلك خدمة مدفوعة.
                            </div>

                                <input type="hidden" name="access_plans[{{ $code }}][chat_enabled]" value="0">
                                <label class="course-editor__inline-check course-editor__inline-check--spaced">
                                    <input type="checkbox" name="access_plans[{{ $code }}][chat_enabled]" value="1" {{ old("access_plans.$code.chat_enabled", $plan?->chat_enabled) ? 'checked' : '' }}>
                                    تفعيل Rokn AI
                                </label>
                                <div class="form-row">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">إجمالي الرسائل</label>
                                        <input class="form-control-modern" type="number" min="1" name="access_plans[{{ $code }}][chat_message_limit]" value="{{ old("access_plans.$code.chat_message_limit", $plan?->chat_message_limit ?? 1) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">أقصى رد</label>
                                        <input class="form-control-modern" type="number" min="80" max="{{ config('openrouter.max_tokens', 500) }}" name="access_plans[{{ $code }}][max_output_tokens]" value="{{ old("access_plans.$code.max_output_tokens", $plan?->max_output_tokens ?? 320) }}">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">ميزانية التوكنز</label>
                                        <input class="form-control-modern" type="number" min="100" name="access_plans[{{ $code }}][chat_token_budget]" value="{{ old("access_plans.$code.chat_token_budget", $plan?->chat_token_budget ?? 100) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حد تكلفة الخطة بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0.000001" step="0.000001" name="access_plans[{{ $code }}][ai_budget_usd]" value="{{ old("access_plans.$code.ai_budget_usd", $plan?->ai_budget_usd ?? .1) }}">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حجز آمن للطلب بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0.000001" step="0.000001" name="access_plans[{{ $code }}][request_reserve_usd]" value="{{ old("access_plans.$code.request_reserve_usd", $plan?->request_reserve_usd ?? .01) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">النموذج</label>
                                        <select class="form-control-modern" name="access_plans[{{ $code }}][model_override]">
                                            <option value="">نموذج الكورس</option>
                                            @foreach($allowedAiModels as $modelName)
                                                <option value="{{ $modelName }}" {{ old("access_plans.$code.model_override", $plan?->model_override) === $modelName ? 'selected' : '' }}>{{ $modelName }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <label class="form-label-modern">ما يحدث بعد تسليم المشروع</label>
                                <select class="form-control-modern" name="access_plans[{{ $code }}][project_feedback_level]">
                                    @foreach(['pass_only' => 'عبور فقط بلا تقرير', 'report' => 'تقرير داخل شات ركن دون رد', 'enhanced' => 'تقرير ثم محادثة داخل شات ركن'] as $level => $levelLabel)
                                        <option value="{{ $level }}" {{ old("access_plans.$code.project_feedback_level", $plan?->project_feedback_level) === $level ? 'selected' : '' }}>{{ $levelLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="form-row course-editor__form-row--top">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">توكنز مراجعات المشاريع</label>
                                        <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][project_feedback_token_budget]" value="{{ old("access_plans.$code.project_feedback_token_budget", $plan?->project_feedback_token_budget ?? 0) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حد تكلفة المراجعات بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0" step="0.000001" name="access_plans[{{ $code }}][project_feedback_budget_usd]" value="{{ old("access_plans.$code.project_feedback_budget_usd", $plan?->project_feedback_budget_usd ?? 0) }}">
                                    </div>
                                </div>
                                <label class="form-label-modern">حجز الطلب الواحد للمراجعة بالدولار</label>
                                <input class="form-control-modern" type="number" min="0" step="0.000001" name="access_plans[{{ $code }}][project_feedback_reserve_usd]" value="{{ old("access_plans.$code.project_feedback_reserve_usd", $plan?->project_feedback_reserve_usd ?? 0) }}">
                                <div class="form-row course-editor__form-row--top">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">رسائل متابعة التقرير</label>
                                        <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][project_followup_message_limit]" value="{{ old("access_plans.$code.project_followup_message_limit", $plan?->project_followup_message_limit ?? 0) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">توكنز متابعة التقرير</label>
                                        <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][project_followup_token_budget]" value="{{ old("access_plans.$code.project_followup_token_budget", $plan?->project_followup_token_budget ?? 0) }}">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حد تكلفة المتابعة بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0" step="0.000001" name="access_plans[{{ $code }}][project_followup_budget_usd]" value="{{ old("access_plans.$code.project_followup_budget_usd", $plan?->project_followup_budget_usd ?? 0) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حجز رسالة المتابعة بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0" step="0.000001" name="access_plans[{{ $code }}][project_followup_reserve_usd]" value="{{ old("access_plans.$code.project_followup_reserve_usd", $plan?->project_followup_reserve_usd ?? 0) }}">
                                    </div>
                                </div>
                                <input type="hidden" name="access_plans[{{ $code }}][project_output_enabled]" value="0">
                                <label class="course-editor__inline-check course-editor__inline-check--top">
                                    <input type="checkbox" name="access_plans[{{ $code }}][project_output_enabled]" value="1" {{ old("access_plans.$code.project_output_enabled", $plan?->project_output_enabled) ? 'checked' : '' }}>
                                    السماح بنموذج محسّن إذا كان من مخرجات النموذج الطبيعية
                                </label>
                            <input type="hidden" name="access_plans[{{ $code }}][certificate_enabled]" value="0">
                            <label class="course-editor__inline-check course-editor__inline-check--top">
                                <input type="checkbox" name="access_plans[{{ $code }}][certificate_enabled]" value="1" {{ old("access_plans.$code.certificate_enabled", $plan?->certificate_enabled ?? true) ? 'checked' : '' }}>
                                إصدار شهادة عند استيفاء شروط الكورس
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
