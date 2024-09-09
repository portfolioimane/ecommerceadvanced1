<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;

class ProductSeeder extends Seeder
{
    public function run()
    {
        // Fetch existing categories
        $categories = Category::all();

        // Add sample products with category_id
        Product::create([
            'name' => 'Smartphone',
            'description' => 'Latest model smartphone with all the features.',
            'price' => 699.99,
            'stock' => 50,
            'image' => 'smartphone.jpg',
            'category_id' => $categories->where('name', 'Electronics')->first()->id
        ]);

        Product::create([
            'name' => 'Sofa',
            'description' => 'Comfortable leather sofa.',
            'price' => 899.99,
            'stock' => 20,
            'image' => 'sofa.jpg',
            'category_id' => $categories->where('name', 'Furniture')->first()->id
        ]);

        Product::create([
            'name' => 'Jacket',
            'description' => 'Warm winter jacket.',
            'price' => 129.99,
            'stock' => 30,
            'image' => 'jacket.jpg',
            'category_id' => $categories->where('name', 'Clothing')->first()->id
        ]);
        // Add more products as needed
    }
}
