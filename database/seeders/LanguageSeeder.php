<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Language::upsert([
            ['code' => 'en', 'name' => 'English', 'is_active' => true, 'is_rtl' => false],
            ['code' => 'de', 'name' => 'Deutsch', 'is_active' => true, 'is_rtl' => false],
            ['code' => 'fr', 'name' => 'Français', 'is_active' => true, 'is_rtl' => false],
            ['code' => 'ar', 'name' => 'العربية', 'is_active' => true, 'is_rtl' => true],
        ], ['code'], ['name', 'is_active', 'is_rtl']);
    }
}
