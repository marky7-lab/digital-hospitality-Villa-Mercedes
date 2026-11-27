<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Models\Faq;
use App\Services\Admin\FaqManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminFaqController extends Controller
{
    public function __construct(
        private readonly FaqManagementService $service
    ) {}

    public function index(): View
    {
        $faqs = Faq::with('category')->orderBy('faq_category_id')->orderBy('sort_order')->get();
        $categories = \App\Models\FaqCategory::orderBy('sort_order')->get();

        return view('admin.faqs.index', compact('faqs', 'categories'));
    }

    public function store(StoreFaqRequest $request): RedirectResponse
    {
        $this->service->createFaq($request->user(), $request->validated());

        return redirect()->back()->with('success', 'FAQ added.');
    }

    public function update(Request $request, Faq $faq): RedirectResponse
    {
        $validated = $request->validate([
            'faq_category_id' => 'required|integer|exists:faq_categories,id',
            'question' => 'required|string|max:1000',
            'answer' => 'required|string|max:5000',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $this->service->updateFaq($request->user(), $faq, $validated);

        return redirect()->back()->with('success', 'FAQ updated.');
    }

    public function toggleStatus(Request $request, Faq $faq): RedirectResponse
    {
        $this->service->toggleFaqStatus($request->user(), $faq, !$faq->is_active);

        return redirect()->back()->with('success', 'FAQ visibility updated.');
    }
}
