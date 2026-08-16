<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $levels = [
            [
                'name_ar' => 'مبتدئ',
                'name_en' => 'Beginner',
                'description_ar' => 'المستوى الأول للمبتدئين',
                'description_en' => 'First level for beginners',
                'order' => 1,
            ],
            [
                'name_ar' => 'متوسط',
                'name_en' => 'Intermediate',
                'description_ar' => 'المستوى الثاني للمتوسطين',
                'description_en' => 'Second level for intermediate students',
                'order' => 2,
            ],
            [
                'name_ar' => 'متقدم',
                'name_en' => 'Advanced',
                'description_ar' => 'المستوى الثالث للمتقدمين',
                'description_en' => 'Third level for advanced students',
                'order' => 3,
            ],
        ];

        foreach ($levels as $levelData) {
            Level::updateOrCreate(
                ['name_en' => $levelData['name_en']],
                $levelData
            );
        }
    }
}
