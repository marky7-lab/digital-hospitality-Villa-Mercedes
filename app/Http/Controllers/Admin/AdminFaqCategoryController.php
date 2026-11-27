<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqCategoryRequest;
use App\Models\FaqCategory;
use App\Services\Admin\FaqManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminFaqCategoryController extends Controller
{
    public function __construct(
        private readonly FaqManagementService $service
    ) {}

    public function index(): View
    {
        $categories = FaqCategory::orderBy('sort_order')->get();

        return view('admin.faq_categories.index', compact('categories'));
    }

    public function store(StoreFaqCategoryRequest $request): RedirectResponse
    {
        $this->service->createCategory($request->user(), $request->validated());

        return redirect()->back()->with('success', 'FAQ category added.');
    }

    public function update(Request $request, FaqCategory $faqCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:faq_categories,slug,' . $faqCategory->id,
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $this->service->updateCategory($request->user(), $faqCategory, $validated);

        return redirect()->back()->with('success', 'FAQ category updated.');
    }

    public function toggleStatus(Request $request, FaqCategory $faqCategory): RedirectResponse
    {
        $this->service->toggleCategoryStatus($request->user(), $faqCategory, !$faqCategory->is_active);

        return redirect()->back()->with('success', 'FAQ category visibility updated.');
    }
}
