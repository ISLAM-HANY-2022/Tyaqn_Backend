<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. إضافة مستخدم تجريبي (كما في شاشة Profile)
        $userId = DB::table('users')->insertGetId([
            'name' => 'Ethan Carter',
            'email' => 'ethan@example.com',
            'password' => Hash::make('password'),
            'job_title' => 'Journalist',
            'bio' => 'Dedicated to truth in the digital age.',
            'profile_image' => 'https://plus.unsplash.com/premium_photo-1689568126014-06fea9d5d341?w=600&auto=format&fit=crop&q=60&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MXx8cHJvZmlsZXxlbnwwfHwwfHx8MA%3D%3D',
            'created_at' => now(),
        ]);

        // 2. إضافة أقسام (كما في شاشة البحث)
        $catId = DB::table('categories')->insertGetId(['name' => 'Technology']);
        DB::table('categories')->insert(['name' => 'Health']);
        DB::table('categories')->insert(['name' => 'Politics']);

        // 3. إضافة مقال (نفس الموجود في صورة فيجما Article)
        DB::table('articles')->insert([
            'category_id' => $catId,
            'title' => 'Debunking the Myth: The Truth Behind the Viral Photo',
            'summary' => 'In the age of social media, misinformation spreads like wildfire.',
            'content' => 'Full article content about the viral photo analysis...',
            'image_url' => 'https://via.placeholder.com/400x200',
            'created_at' => now(),
        ]);

        // 4. إضافة عملية فحص (كما في شاشة My History)
        DB::table('verifications')->insert([
            'user_id' => $userId,
            'title' => 'Climate Change Fact Check',
            'input_data' => 'Is climate change a hoax?',
            'type' => 'text',
            'result_status' => 'Real',
            'description_result' => 'Climate change is a real phenomenon supported by evidence.',
            'percentage' => 98,
            'is_bookmarked' => true,
            'created_at' => now(),
        ]);

        // 5. إضافة سؤال اختبار (كما في شاشة Daily Quiz)
        DB::table('quizzes')->insert([
            'question' => 'The Earth is flat.',
            'option_a' => 'True',
            'option_b' => 'False',
            'correct_answer' => 'False',
            'explanation' => 'The Earth is an oblate spheroid, not flat. This has been proven through centuries of scientific observation.',
            'created_at' => now(),
        ]);

        // 6. إضافة إشعار (كما في شاشة Notifications)
        DB::table('notifications')->insert([
            'user_id' => $userId,
            'title' => 'Your verification is ready',
            'message' => 'Your verification for "Vaccines cause autism" is completed.',
            'is_read' => false,
            'created_at' => now()->subMinutes(10),
        ]);
    }
}