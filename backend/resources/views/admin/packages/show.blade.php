@extends('admin.layouts.app')

@section('page.title', 'تفاصيل الباقة')

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <strong class="card-title">تفاصيل الباقة</strong>
            </div>
            <div class="card-body">
                <table class="table">
                    <tbody>
                        <tr>
                            <th>الاسم (AR)</th>
                            <td>{{ $package->name_ar }}</td>
                        </tr>
                        <tr>
                            <th>الاسم (EN)</th>
                            <td>{{ $package->name_en }}</td>
                        </tr>
                        <tr>
                            <th>السعر</th>
                            <td>{{ $package->price }}</td>
                        </tr>
                        <tr>
                            <th>العملات (Coins)</th>
                            <td>{{ $package->coins }}</td>
                        </tr>
                        <tr>
                            <th>تاريخ الإنشاء</th>
                            <td>{{ $package->created_at }}</td>
                        </tr>
                    </tbody>
                </table>
                <a href="{{ route('admin.packages.edit', $package->id) }}" class="btn btn-warning mt-3">
                    <i class="fa fa-edit"></i> تعديل
                </a>
                <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary mt-3">
                    <i class="fa fa-arrow-left"></i> رجوع
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <strong class="card-title">آخر عمليات الشراء</strong>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>السعر المدفوع</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($package->purchases as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->pivot->price }}</td>
                            <td>{{ $user->pivot->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center">لا توجد عمليات شراء لهذه الباقة بعد.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
