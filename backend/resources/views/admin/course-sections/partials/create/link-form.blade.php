                <!-- Link Form -->
                <div class="form-section dynamic-form" id="link-form" data-type="link">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-external-link"></i>
                        </div>
                        <h3 class="section-title">تفاصيل الرابط</h3>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="link_title_ar">العنوان بالعربية *</label>
                                <input type="text" id="link_title_ar" name="link_title_ar" class="form-control"
                                       value="{{ old('link_title_ar') }}" placeholder="أدخل العنوان بالعربية" data-required="true">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="link_title_en">العنوان بالإنجليزية *</label>
                                <input type="text" id="link_title_en" name="link_title_en" class="form-control"
                                       value="{{ old('link_title_en') }}" placeholder="Enter English title" data-required="true">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="link_url">رابط الموقع *</label>
                        <input type="url" id="link_url" name="link_url" class="form-control"
                               value="{{ old('link_url') }}" placeholder="https://..." data-required="true">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="link_type">نوع الرابط *</label>
                        <select id="link_type" name="link_type" class="form-select" data-required="true">
                            <option value="">اختر نوع الرابط</option>
                            <option value="resource" {{ old('link_type') == 'resource' ? 'selected' : '' }}>مورد تعليمي</option>
                            <option value="video" {{ old('link_type') == 'video' ? 'selected' : '' }}>فيديو</option>
                            <option value="document" {{ old('link_type') == 'document' ? 'selected' : '' }}>مستند</option>
                            <option value="website" {{ old('link_type') == 'website' ? 'selected' : '' }}>موقع ويب</option>
                        </select>
                    </div>
                </div>
