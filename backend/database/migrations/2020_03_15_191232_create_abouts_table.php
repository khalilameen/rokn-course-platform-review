<?php

declare(strict_types=1);

use App\Models\About;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAboutsTable extends Migration
{
    public function up(): void
    {
        Schema::create('abouts', function (Blueprint $table): void {
            $table->id();
            $table->text('about_ar');
            $table->text('about_en');
            $table->longText('privacy_ar');
            $table->longText('privacy_en');
            $table->longText('policy_ar');
            $table->longText('policy_en');
            $table->timestamps();
        });

        About::query()->create([
            'about_ar' => 'ركن منصة تعليمية تعتمد على خطوات قصيرة وتطبيق عملي.',
            'about_en' => 'Rokn is a learning platform built around short, practical steps.',
            'privacy_ar' => 'راجع سياسة الخصوصية المنشورة داخل التطبيق.',
            'privacy_en' => 'See the privacy policy published in the application.',
            'policy_ar' => 'راجع شروط الاستخدام المنشورة داخل التطبيق.',
            'policy_en' => 'See the terms of use published in the application.',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('abouts');
    }
}
