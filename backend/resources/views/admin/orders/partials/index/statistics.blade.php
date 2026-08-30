    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--total mx-auto">
                        <i class="ti-receipt text-white"></i>
                    </div>
                    <div class="stat-title">إجمالي الطلبات</div>
                    <h3 class="stat-value">{{ number_format($stats['total']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--pending mx-auto">
                        <i class="ti-time text-white"></i>
                    </div>
                    <div class="stat-title">في الانتظار</div>
                    <h3 class="stat-value">{{ number_format($stats['pending']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--approved mx-auto">
                        <i class="ti-check text-white"></i>
                    </div>
                    <div class="stat-title">مُعتمد</div>
                    <h3 class="stat-value">{{ number_format($stats['approved']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--rejected mx-auto">
                        <i class="ti-close text-white"></i>
                    </div>
                    <div class="stat-title">مرفوض</div>
                    <h3 class="stat-value">{{ number_format($stats['rejected']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--cancelled mx-auto">
                        <i class="ti-minus text-dark"></i>
                    </div>
                    <div class="stat-title">ملغي</div>
                    <h3 class="stat-value">{{ number_format($stats['cancelled']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper stat-icon--revenue mx-auto">
                        <i class="ti-money text-white"></i>
                    </div>
                    <div class="stat-title">إجمالي مبيعات القنوات</div>
                    <h3 class="stat-value stat-value--amount">{{ number_format($stats['total_amount'], 2) }}</h3>
                    <small class="text-muted">جنيه</small>
                    <br><small class="text-muted">المؤكد أو التقديري، دون الاختبارات</small>
                </div>
            </div>
        </div>
    </div>
