<?php

declare(strict_types=1);

namespace App\Http\Controllers\Categories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\DeleteCategoryRequest;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): View
    {
        $this->authorize('viewAny', Category::class);

        return view('categories.index', ['categories' => $this->categories->paginate()]);
    }

    public function create(): View
    {
        $this->authorize('create', Category::class);

        return view('categories.create', ['category' => new Category(['active' => true])]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $this->categories->create($request->payload());

        return redirect()->route('categories.index')->with('status', 'category-created');
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        return view('categories.edit', ['category' => $category]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $this->categories->update($category, $request->payload());

        return redirect()->route('categories.index')->with('status', 'category-updated');
    }

    public function destroy(DeleteCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $this->categories->delete($category);

        return redirect()->route('categories.index')->with('status', 'category-deleted');
    }
}
