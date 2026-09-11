<?php
// database/seeders/PreferenceSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Preference;
use Illuminate\Support\Str;

class PreferenceSeeder extends Seeder
{
    public function run(): void
    {
        $preferences = [
            // Catégorie: Interests
            [
                'name' => 'Music',
                'slug' => 'music',
                'icon' => 'Music',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Art',
                'slug' => 'art',
                'icon' => 'Palette',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Photo',
                'slug' => 'photo',
                'icon' => 'Camera',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Gaming',
                'slug' => 'gaming',
                'icon' => 'Gamepad2',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Sports',
                'slug' => 'sports',
                'icon' => 'Heart',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Science',
                'slug' => 'science',
                'icon' => 'Beaker',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Tech',
                'slug' => 'tech',
                'icon' => 'TrendingUp',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'icon' => 'Briefcase',
                'category' => 'interests',
                'is_active' => true,
            ],
            [
                'name' => 'Creative',
                'slug' => 'creative',
                'icon' => 'Sparkles',
                'category' => 'interests',
                'is_active' => true,
            ],
            // Catégorie: Skills
            [
                'name' => 'Programming',
                'slug' => 'programming',
                'icon' => 'Code',
                'category' => 'skills',
                'is_active' => true,
            ],
            [
                'name' => 'Design',
                'slug' => 'design',
                'icon' => 'PenTool',
                'category' => 'skills',
                'is_active' => true,
            ],
            [
                'name' => 'Writing',
                'slug' => 'writing',
                'icon' => 'Pen',
                'category' => 'skills',
                'is_active' => true,
            ],
            [
                'name' => 'Marketing',
                'slug' => 'marketing',
                'icon' => 'Megaphone',
                'category' => 'skills',
                'is_active' => true,
            ],
            // Catégorie: Languages
            [
                'name' => 'English',
                'slug' => 'english',
                'icon' => 'Languages',
                'category' => 'languages',
                'is_active' => true,
            ],
            [
                'name' => 'French',
                'slug' => 'french',
                'icon' => 'Languages',
                'category' => 'languages',
                'is_active' => true,
            ],
            [
                'name' => 'Spanish',
                'slug' => 'spanish',
                'icon' => 'Languages',
                'category' => 'languages',
                'is_active' => true,
            ],
        ];

        foreach ($preferences as $preference) {
            Preference::updateOrCreate(
                ['slug' => $preference['slug']],
                $preference
            );
        }

        $this->command->info('Preferences seeded successfully!');
    }
}