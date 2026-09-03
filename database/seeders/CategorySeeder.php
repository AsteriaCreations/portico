<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Owner', 'is_comped' => true],
            ['name' => 'Manager', 'is_comped' => true],
            ['name' => 'Emeritus', 'is_comped' => true],
            ['name' => 'Staff', 'is_comped' => true],
            ['name' => 'Former_Staff', 'is_comped' => false],
            ['name' => 'SH', 'is_comped' => false],
            ['name' => 'Irregular', 'is_comped' => false],
            ['name' => 'Prospective', 'is_comped' => false],
            ['name' => 'Guest', 'is_comped' => false],
        ];

        foreach ($categories as $sortOrder => $category) {
            Category::create([
                'name' => $category['name'],
                'is_comped' => $category['is_comped'],
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
