            @if(strtolower((string) optional(auth()->user())->role) === 'admin')
            @php
                $accessPlansByCode = $course->accessPlans->keyBy('code');
                $planLabels = [
                    'basic' => ['التعلّم', 'الكورس والمشروعات والعبور من غير تكلفة ذكاء اصطناعي'],
                    'guided' => ['التعلّم بإرشاد', 'أسئلة اقتصادية وتقرير عملي على المشروع'],
                    'mentor' => ['التعلّم بمتابعة', 'مساحة أسئلة أكبر ومراجعة أعمق ونموذج محسّن عند ملاءمته'],
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
                        @php $plan = $accessPlansByCode->get($code); @endphp
                        <div class="course-editor__plan-card">
                            <div class="course-editor__plan-title">{{ $label }}</div>
                            <div class="course-editor__plan-description">{{ $description }}</div>
                            @if($plan && $code !== 'basic')
                                @php
                                    $planMaxCost = (float) $plan->ai_budget_usd + (float) $plan->project_feedback_budget_usd;
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
                                    <span>المبيعات: <strong>{{ number_format($stats['sales_count']) }}</strong></span>
                                    <span>إجمالي العملات: <strong>{{ number_format($stats['total_coins']) }}</strong></span>
                                    <span>مدفوعة: <strong>{{ number_format($stats['paid_coins']) }}</strong></span>
                                    <span>مكافآت: <strong>{{ number_format($stats['reward_coins']) }}</strong></span>
                                    <span>أسئلة الشات: <strong>{{ number_format($stats['chat_requests']) }}</strong></span>
                                    <span>توكنز الشات: <strong>{{ number_format($stats['chat_tokens']) }}</strong></span>
                                    <span>تكلفة الشات: <strong>${{ number_format($stats['chat_cost_usd'], 6) }}</strong></span>
                                    <span>مراجعات المشاريع: <strong>{{ number_format($stats['project_requests']) }}</strong></span>
                                    <span>توكنز المراجعات: <strong>{{ number_format($stats['project_tokens']) }}</strong></span>
                                    <span>تكلفة المراجعات: <strong>${{ number_format($stats['project_cost_usd'], 6) }}</strong></span>
                                    <span class="course-editor__plan-stats-total">إجمالي تكلفة OpenRouter الفعلية: <strong>${{ number_format($stats['chat_cost_usd'] + $stats['project_cost_usd'], 6) }}</strong></span>
                                </div>
                            @endif
                            <input type="hidden" name="access_plans[{{ $code }}][name_ar]" value="{{ $label }}">
                            <input type="hidden" name="access_plans[{{ $code }}][is_active]" value="0">
                            <label class="course-editor__inline-check course-editor__inline-check--spaced">
                                <input type="checkbox" name="access_plans[{{ $code }}][is_active]" value="1" {{ old("access_plans.$code.is_active", $plan?->is_active) ? 'checked' : '' }}>
                                متاحة للشراء
                            </label>
                            <label class="form-label-modern">السعر بعملات ركن</label>
                            <input class="form-control-modern" type="number" min="0" name="access_plans[{{ $code }}][price_coins]" value="{{ old("access_plans.$code.price_coins", $plan?->price_coins ?? 0) }}" required>

                            @if($code !== 'basic')
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
                                <label class="form-label-modern">مراجعة المشروع</label>
                                <select class="form-control-modern" name="access_plans[{{ $code }}][project_feedback_level]">
                                    @foreach(['pass_only' => 'عبور فقط', 'report' => 'تقرير وتوصيات', 'enhanced' => 'مراجعة أعمق + نموذج عند الملاءمة'] as $level => $levelLabel)
                                        <option value="{{ $level }}" {{ old("access_plans.$code.project_feedback_level", $plan?->project_feedback_level) === $level ? 'selected' : '' }}>{{ $levelLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="form-row course-editor__form-row--top">
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">توكنز مراجعات المشاريع</label>
                                        <input class="form-control-modern" type="number" min="100" name="access_plans[{{ $code }}][project_feedback_token_budget]" value="{{ old("access_plans.$code.project_feedback_token_budget", $plan?->project_feedback_token_budget ?? 100) }}">
                                    </div>
                                    <div class="form-group-modern">
                                        <label class="form-label-modern">حد تكلفة المراجعات بالدولار</label>
                                        <input class="form-control-modern" type="number" min="0.000001" step="0.000001" name="access_plans[{{ $code }}][project_feedback_budget_usd]" value="{{ old("access_plans.$code.project_feedback_budget_usd", $plan?->project_feedback_budget_usd ?? .1) }}">
                                    </div>
                                </div>
                                <label class="form-label-modern">حجز الطلب الواحد للمراجعة بالدولار</label>
                                <input class="form-control-modern" type="number" min="0.000001" step="0.000001" name="access_plans[{{ $code }}][project_feedback_reserve_usd]" value="{{ old("access_plans.$code.project_feedback_reserve_usd", $plan?->project_feedback_reserve_usd ?? .02) }}">
                                <input type="hidden" name="access_plans[{{ $code }}][project_output_enabled]" value="0">
                                <label class="course-editor__inline-check course-editor__inline-check--top">
                                    <input type="checkbox" name="access_plans[{{ $code }}][project_output_enabled]" value="1" {{ old("access_plans.$code.project_output_enabled", $plan?->project_output_enabled) ? 'checked' : '' }}>
                                    السماح بنموذج محسّن إذا كان من مخرجات النموذج الطبيعية
                                </label>
                            @else
                                <input type="hidden" name="access_plans[basic][chat_enabled]" value="0">
                                <input type="hidden" name="access_plans[basic][chat_message_limit]" value="0">
                                <input type="hidden" name="access_plans[basic][chat_token_budget]" value="0">
                                <input type="hidden" name="access_plans[basic][ai_budget_usd]" value="0">
                                <input type="hidden" name="access_plans[basic][request_reserve_usd]" value="0">
                                <input type="hidden" name="access_plans[basic][project_feedback_token_budget]" value="0">
                                <input type="hidden" name="access_plans[basic][project_feedback_budget_usd]" value="0">
                                <input type="hidden" name="access_plans[basic][project_feedback_reserve_usd]" value="0">
                                <input type="hidden" name="access_plans[basic][max_output_tokens]" value="260">
                                <input type="hidden" name="access_plans[basic][project_feedback_level]" value="pass_only">
                            @endif
                            <input type="hidden" name="access_plans[{{ $code }}][certificate_enabled]" value="1">
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
