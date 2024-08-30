<?php

namespace App\Http\Controllers;

use App\Models\Category; // Đừng quên nạp model Category
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // Hiển thị danh sách categories
    public function index()
    {
        $categories = Category::all();
        return view('categories.index', compact('categories'));
    }

    // Hiển thị form tạo mới category
    public function create()
    {
        return view('categories.create');
    }

    // Lưu một category mới vào database
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Category::create($request->all());

        return redirect()->route('categories.index')
                         ->with('success', 'Category created successfully.');
    }

    // Hiển thị chi tiết một category cụ thể
    public function show(Category $category)
    {
        return view('categories.show', compact('category'));
    }

    // Hiển thị form chỉnh sửa một category
    public function edit(Category $category)
    {
        return view('categories.edit', compact('category'));
    }

    // Cập nhật thông tin một category
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category->update($request->all());

        return redirect()->route('categories.index')
                         ->with('success', 'Category updated successfully.');
    }

    // Xóa một category
    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('categories.index')
                         ->with('success', 'Category deleted successfully.');
    }
}
