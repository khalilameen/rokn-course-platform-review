<!-- Left Panel -->

<aside id="left-panel" class="left-panel modern-sidebar" aria-label="القائمة الرئيسية">
    <nav class="sidebar-nav">

        <div class="modern-brand">
            <a class="brand-logo" href="{{ route('admin.dashboard') }}">
                <i class="fa fa-graduation-cap brand-icon" aria-hidden="true"></i>
                <span class="brand-text">Rokn</span>
            </a>
            <button
                type="button"
                id="adminSidebarClose"
                class="admin-sidebar-close"
                aria-label="إغلاق القائمة الرئيسية"
                aria-controls="left-panel"
            >
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div id="main-menu" class="main-menu">
            <ul class="modern-nav">
                <!-- Dashboard Section -->
                <li class="nav-item{{ isRouteActive('admin.dashboard') ? ' active' : '' }}">
                    <a href="{{ route('admin.dashboard') }}" class="nav-link">
                        <i class="menu-icon fa fa-dashboard"></i>
                        <span class="menu-text">الرئيسية</span>
                    </a>
                </li>
                <!--
                <li class="nav-item{{ isRouteActive('admin.urgent-tasks.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.urgent-tasks.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-exclamation-triangle"></i>
                        <span class="menu-text">المهام العاجلة</span>
                    </a>
                </li>-->

                <!-- Academic Management Section -->
                <li class="menu-divider"><span>الإدارة الأكاديمية</span></li>
                <!--
                <li class="nav-item{{ isRouteActive('admin.grades.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.grades.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-graduation-cap"></i>
                        <span class="menu-text">المراحل الدراسية</span>
                    </a>
                </li>-->

                <li class="nav-item{{ isRouteActive('admin.classifications.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.classifications.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-tags"></i>
                        <span class="menu-text">التصنيفات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.levels.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.levels.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-trophy"></i>
                        <span class="menu-text">المستويات</span>
                    </a>
                </li>



                <li class="nav-item{{ isRouteActive('admin.courses.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.courses.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-book"></i>
                        <span class="menu-text">الكورسات</span>
                    </a>
                </li>

                @if(auth()->user()?->role === 'admin')
                <li class="nav-item{{ isRouteActive('admin.teachers.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.teachers.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-users"></i>
                        <span class="menu-text">المعلمون</span>
                    </a>
                </li>

                @endif

                <!-- Students Section -->
                <li class="menu-divider"><span>الطلاب والتقييم</span></li>

                @if(auth()->user()?->role === 'admin')
                <li class="nav-item{{ isRouteActive('admin.users.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.users.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-user-circle"></i>
                        <span class="menu-text">الطلاب</span>
                    </a>
                </li>

                @endif

                <li class="nav-item{{ isRouteActive('admin.student-progress.index') ? ' active' : '' }}">
                    <a href="{{ route('admin.student-progress.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-bar-chart-o"></i>
                        <span class="menu-text">تقدم الطلاب</span>
                    </a>
                </li>
                @if(auth()->user()?->role === 'admin')
                <li class="nav-item{{ isRouteActive('admin.product-operations.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.product-operations.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-check-square-o"></i>
                        <span class="menu-text">مركز تشغيل المنتج</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.playback-operations.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.playback-operations.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-play-circle"></i>
                        <span class="menu-text">مراقبة الفيديو</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.user-sessions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.user-sessions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-laptop"></i>
                        <span class="menu-text">جلسات الأجهزة</span>
                    </a>
                </li>
                <li class="nav-item{{ isRouteActive('admin.feedback.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.feedback.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-commenting-o"></i>
                        <span class="menu-text">ملاحظات التطبيق</span>
                    </a>
                </li>
                @endif

                <li class="nav-item{{ isRouteActive('admin.project-submissions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.project-submissions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-tasks"></i>
                        <span class="menu-text">مراجعة المشاريع</span>
                    </a>
                </li>
<!--
                <li class="nav-item{{ isRouteActive('admin.exam-results.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.exam-results.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-line-chart"></i>
                        <span class="menu-text">نتائج الامتحانات</span>
                    </a>
                </li>-->

                @if(auth()->user()?->role === 'admin')
                <!-- Financial Section -->
                <li class="menu-divider"><span>المالية والمبيعات</span></li>

                <li class="nav-item{{ isRouteActive('admin.course-codes.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.course-codes.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-key"></i>
                        <span class="menu-text">إدارة الأكواد</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.orders.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.orders.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-shopping-cart"></i>
                        <span class="menu-text">المشتريات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.payment-reconciliation-findings.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.payment-reconciliation-findings.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-balance-scale"></i>
                        <span class="menu-text">مراجعة تسوية المدفوعات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.packages.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.packages.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-cubes"></i>
                        <span class="menu-text">الباقات</span>
                    </a>
                </li>
                @endif

                <li class="nav-item{{ isRouteActive('admin.paths.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.paths.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-road"></i>
                        <span class="menu-text">المسارات</span>
                    </a>
                </li>
                
                @if(auth()->user()?->role === 'admin')
                <li class="nav-item{{ isRouteActive('admin.coin-earning-methods.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.coin-earning-methods.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-money"></i>
                        <span class="menu-text">طرق ربح العملات</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.bills.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.bills.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-file-text"></i>
                        <span class="menu-text">الفواتير</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.payment-methods.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.payment-methods.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-credit-card"></i>
                        <span class="menu-text">طرق الدفع</span>
                    </a>
                </li>

                <!-- Settings Section -->
                <li class="menu-divider"><span>الإعدادات</span></li>
                <!--
                <li class="nav-item{{ isRouteActive('admin.design-settings.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.design-settings.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-paint-brush"></i>
                        <span class="menu-text">إعدادات التصميم</span>
                    </a>
                </li>-->

                <li class="nav-item{{ isRouteActive('admin.settings') ? ' active' : '' }}">
                    <a href="{{ route('admin.settings') }}" class="nav-link">
                        <i class="menu-icon fa fa-cog"></i>
                        <span class="menu-text">إعدادات الموقع</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.app-versions.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.app-versions.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-mobile"></i>
                        <span class="menu-text">إصدارات التطبيق</span>
                    </a>
                </li>

                <li class="nav-item{{ isRouteActive('admin.admin_data') ? ' active' : '' }}">
                    <a href="{{ route('admin.admin_data') }}" class="nav-link">
                        <i class="menu-icon fa fa-lock"></i>
                        <span class="menu-text">تغيير كلمة المرور</span>
                    </a>
                </li>
                @endif
<!--
                <li class="nav-item{{ isRouteActive('admin.student-platform') ? ' active' : '' }}">
                    <a href="{{ route('admin.student-platform') }}" class="nav-link">
                        <i class="menu-icon fa fa-globe"></i>
                        <span class="menu-text">منصة الطالب</span>
                    </a>
                </li>
-->
                @if(auth()->user()?->role === 'admin')
                <!-- Communication Section -->
                <li class="menu-divider"><span>التواصل</span></li>

                <li class="nav-item{{ isRouteActive('admin.contacts') ? ' active' : '' }}">
                    <a href="{{ route('admin.contacts.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-comments"></i>
                        <span class="menu-text">اتصل بنا</span>
                        @php
                            $unreadCount = \App\Models\Contact::where('read', false)->count();
                        @endphp
                        @if($unreadCount > 0)
                            <span class="notification-badge">{{ $unreadCount }}</span>
                        @endif
                    </a>
                </li>
                @if(auth()->user()?->role === 'admin')
                <li class="nav-item{{ isRouteActive('admin.notifications.*') ? ' active' : '' }}">
                    <a href="{{ route('admin.notifications.index') }}" class="nav-link">
                        <i class="menu-icon fa fa-bell"></i>
                        <span class="menu-text">إشعارات الطلاب</span>
                    </a>
                </li>
                @endif
                @endif

            </ul>
        </div>
    </nav>
</aside>
