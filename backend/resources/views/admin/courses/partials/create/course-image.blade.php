            <!-- Course Image Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-image"></i>
                    </div>
                    صورة الكورس
                </h2>

                <div class="form-group-modern">
                    <label class="form-label-modern">
                        <i class="fa fa-camera label-icon"></i>
                        صورة الكورس
                    </label>
                    <label class="file-upload-area" for="image">
                        <div class="upload-icon">
                            <i class="fa fa-cloud-upload"></i>
                        </div>
                        <div class="upload-text">اضغط لاختيار صورة أو اسحبها هنا</div>
                        <div class="upload-subtext">PNG أو JPG أو WebP حتى 6MB وبحد أدنى 640×360</div>
                        <input type="file" name="image" id="image" class="file-input-hidden" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <div id="imagePreview"></div>
                    @if ($errors->has('image'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle"></i>
                            {{ $errors->first('image') }}
                        </div>
                    @endif
                </div>
            </div>
