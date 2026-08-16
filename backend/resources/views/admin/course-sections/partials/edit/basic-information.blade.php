                <!-- Section Basic Info -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-info-circle"></i>
                        </div>
                        <h3 class="section-title">معلومات القسم الأساسية</h3>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="title_ar">عنوان القسم (بالعربية) *</label>
                                <input type="text" id="title_ar" name="title_ar" class="form-control"
                                       value="{{ old('title_ar', $section->title_ar ?? $section->title) }}" placeholder="أدخل العنوان بالعربية" required>
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
                                <label class="form-label" for="title_en">عنوان القسم (بالإنجليزية)</label>
                                <input type="text" id="title_en" name="title_en" class="form-control"
                                       value="{{ old('title_en', $section->title_en) }}" placeholder="Enter English title">
                                @error('title_en')
                                    <div class="error-message">
                                        <i class="fa fa-exclamation-circle"></i>
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="order">
                            ترتيب القسم *
                        </label>
                        <input type="number" id="order" name="order" class="form-control"
                               value="{{ old('order', $section->order) }}" min="1" required>
                    @error('order')
                        <div class="error-message">
                            <i class="fa fa-exclamation-circle"></i>
                            {{ $message }}
                        </div>
                    @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="module_id">
                            الوحدة (اختياري)
                        </label>
                        <select name="module_id" id="module_id" class="form-select">
                            <option value="">-- بدون وحدة (قسم عام) --</option>
                            @foreach($modules as $module)
                                <option value="{{ $module->id }}" {{ old('module_id', $section->module_id) == $module->id ? 'selected' : '' }}>
                                    {{ $module->title }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">يمكنك ربط هذا القسم بوحدة معينة أو تركه كقسم عام</small>
                    </div>
                </div>
