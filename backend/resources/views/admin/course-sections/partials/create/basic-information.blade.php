                <!-- Section Basic Info -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-info-circle"></i>
                        </div>
                        <h3 class="section-title">العنوان والمكان داخل الكورس</h3>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="title_ar">العنوان الذي سيظهر للطالب (بالعربية) *</label>
                                <input type="text" id="title_ar" name="title_ar" class="form-control"
                                       value="{{ old('title_ar') }}" placeholder="مثال: كيف تختار أول عميل؟" required>
                                <small class="text-muted">يظهر في خريطة الكورس وعلى المقطع أو المشروع داخل التطبيق.</small>
                                @error('title_ar')
                                    <div class="error-message">
                                        <i class="fa fa-exclamation-circle"></i>
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="title_en">العنوان الظاهر للطالب (بالإنجليزية)</label>
                                <input type="text" id="title_en" name="title_en" class="form-control"
                                       value="{{ old('title_en') }}" placeholder="Enter English title">
                                @error('title_en')
                                    <div class="error-message">
                                        <i class="fa fa-exclamation-circle"></i>
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    @if(request('return_to') !== 'studio')
                    <div class="form-group">
                        <label class="form-label" for="order">
                            ترتيب القسم *
                        </label>
                        <input type="number" id="order" name="order" class="form-control"
                               value="{{ old('order', $course->sections()->max('order') + 1) }}" min="1" required>
                        @error('order')
                            <div class="error-message">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                    @endif

                    <div class="form-group">
                        <label class="form-label" for="module_id">
                            الوحدة *
                        </label>
                        <select name="module_id" id="module_id" class="form-select" required>
                            <option value="">-- اختر وحدة --</option>
                            @foreach($modules as $module)
                                <option value="{{ $module->id }}" {{ old('module_id', request('module_id')) == $module->id ? 'selected' : '' }}>
                                    {{ $module->title }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">مكان هذا المحتوى داخل خريطة الكورس</small>
                        @error('module_id')
                            <div class="error-message">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
