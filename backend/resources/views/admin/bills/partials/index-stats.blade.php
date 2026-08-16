<!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-total">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-primary">
                        <i class="ti-file text-white"></i>
                    </div>
                    <div class="stat-title">إجمالي الفواتير</div>
                    <h3 class="stat-value">{{ number_format($stats['total']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-pending">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-warning">
                        <i class="ti-time text-white"></i>
                    </div>
                    <div class="stat-title">في الانتظار</div>
                    <h3 class="stat-value">{{ number_format($stats['pending']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-paid">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-success">
                        <i class="ti-check text-white"></i>
                    </div>
                    <div class="stat-title">مدفوع</div>
                    <h3 class="stat-value">{{ number_format($stats['paid']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-overdue">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-error">
                        <i class="ti-close text-white"></i>
                    </div>
                    <div class="stat-title">متأخر</div>
                    <h3 class="stat-value">{{ number_format($stats['overdue']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-amount">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-success">
                        <i class="ti-money text-white"></i>
                    </div>
                    <div class="stat-title">المبلغ المدفوع</div>
                    <h3 class="stat-value stat-value-amount">{{ number_format($stats['total_amount'], 2) }}</h3>
                    <small class="text-muted">جنيه</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stat-card stat-card-pending-amount">
                <div class="card-body text-center">
                    <div class="stat-icon-wrapper mx-auto stat-icon-accent">
                        <i class="ti-money text-white"></i>
                    </div>
                    <div class="stat-title">المبلغ المعلق</div>
                    <h3 class="stat-value stat-value-amount">{{ number_format($stats['pending_amount'], 2) }}</h3>
                    <small class="text-muted">جنيه</small>
                </div>
            </div>
        </div>
    </div>
