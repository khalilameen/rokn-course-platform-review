<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GovernoratesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Array of governorates
        $governorates = [
            ['name' => 'السويس', 'country_id' => 1],
            ['name' => 'القاهره', 'country_id' => 1],
            ['name' => 'الإسكندرية', 'country_id' => 1],
            ['name' => 'الإسماعيلية', 'country_id' => 1],
            ['name' => 'أسوان', 'country_id' => 1],
            ['name' => 'أسيوط', 'country_id' => 1],
            ['name' => 'الأقصر', 'country_id' => 1],
            ['name' => 'البحر الأحمر', 'country_id' => 1],
            ['name' => 'البحيرة', 'country_id' => 1],
            ['name' => 'بني سويف', 'country_id' => 1],
            ['name' => 'بورسعيد', 'country_id' => 1],
            ['name' => 'جنوب سيناء', 'country_id' => 1],
            ['name' => 'الجيزة', 'country_id' => 1],
            ['name' => 'الدقهلية', 'country_id' => 1],
            ['name' => 'دمياط', 'country_id' => 1],
            ['name' => 'سوهاج', 'country_id' => 1],
            ['name' => 'الشرقية', 'country_id' => 1],
            ['name' => 'شمال سيناء', 'country_id' => 1],
            ['name' => 'الغربية', 'country_id' => 1],
            ['name' => 'الفيوم', 'country_id' => 1],
            ['name' => 'القليوبية', 'country_id' => 1],
            ['name' => 'قنا', 'country_id' => 1],
            ['name' => 'كفر الشيخ', 'country_id' => 1],
            ['name' => 'مطروح', 'country_id' => 1],
            ['name' => 'المنوفية', 'country_id' => 1],
            ['name' => 'المنيا', 'country_id' => 1],
            ['name' => 'الوادي الجديد', 'country_id' => 1],
        ];

        // Insert governorates into the database
        DB::table('governorates')->insert($governorates);
    }
}
