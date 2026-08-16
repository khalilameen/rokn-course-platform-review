                <!-- Section Type Selection -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-info-circle"></i>
                        </div>
                        <h3 class="section-title">نوع المحتوى</h3>
                    </div>

                    <div class="section-type-selector">
                        <div class="type-option" data-type="lesson">
                            <div class="type-icon type-lesson">
                                <i class="fa fa-play-circle"></i>
                            </div>
                            <div class="type-name">درس</div>
                            <div class="type-description">درس فيديو تعليمي مع شرح مفصل</div>
                        </div>

                       <!-- commented intentially in case it is needed in the future
                         <div class="type-option" data-type="link">
                            <div class="type-icon type-link">
                                <i class="fa fa-link"></i>
                            </div>
                            <div class="type-name">رابط خارجي</div>
                            <div class="type-description">رابط لمورد أو موقع خارجي</div>
                        </div> 

                        <div class="type-option" data-type="quiz">
                            <div class="type-icon type-quiz">
                                <i class="fa fa-list-ul"></i>
                            </div>
                            <div class="type-name">اختبار</div>
                            <div class="type-description">مجموعة من الأسئلة والتمارين</div>
                        </div>

                        <div class="type-option" data-type="course">
                            <div class="type-icon type-course">
                                <i class="fa fa-book"></i>
                            </div>
                            <div class="type-name">كورس فرعي</div>
                            <div class="type-description">كورس منفصل داخل الكورس الحالي</div>
                        </div>-->

                        <div class="type-option" data-type="project">
                            <div class="type-icon type-project">
                                <i class="fa fa-project-diagram"></i>
                            </div>
                            <div class="type-name">مشروع</div>
                            <div class="type-description">مشروع تطبيقي مع تقييم AI</div>
                        </div>
                    </div>

                    <input type="hidden" name="section_type" id="section_type" value="{{ old('section_type') }}">
                    @error('section_type')
                        <div class="error-message">
                            <i class="fa fa-exclamation-circle"></i>
                            {{ $message }}
                        </div>
                    @enderror
                </div>
