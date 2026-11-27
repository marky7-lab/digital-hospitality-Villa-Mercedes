<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRateRequest;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Rate;
use App\Services\Admin\RateManagementService;
use App\Support\LiveFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminRateController extends Controller
{
    public function __construct(
        private readonly RateManagementService $service
    ) {}

    public function index(Request $request): View
    {
        $query = Rate::with('facility.facilityType');

        // Server-side facility filter
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->input('facility_id'));
        }

        // Server-side facility type filter
        if ($request->filled('facility_type')) {
            $typeModel = FacilityType::where('slug', $request->input('facility_type'))->first();
            if ($typeModel) {
                $query->whereHas('facility', fn ($q) => $q->where('facility_type_id', $typeModel->id));
            }
        }

        // Server-side rate type filter
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        // Active filter
        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by rate name or facility
        if ($request->filled('search')) {
            $like = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($searchQuery) use ($like) {
                $searchQuery->where('name', 'like', $like)
                    ->orWhereHas('facility', function ($facilityQuery) use ($like) {
                        $facilityQuery->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like);
                    });
            });
        }

        $rates = $query->orderBy('facility_id')->orderBy('type')->paginate(20);

        if (LiveFilter::wantsPartial($request)) {
            return view('admin.rates.partials.results', compact('rates'));
        }

        $facilities = Facility::with('facilityType')->where('is_active', true)->orderBy('name')->get();
        $facilityTypes = FacilityType::where('is_active', true)->get();

        return view('admin.rates.index', compact('rates', 'facilities', 'facilityTypes'));
    }

    public function create(Request $request): View
    {
        $facilities = Facility::with('facilityType')->where('is_active', true)->orderBy('name')->get();
        $selectedFacilityId = $request->input('facility_id');

        return view('admin.rates.create', compact('facilities', 'selectedFacilityId'));
    }

    public function show(Rate $rate): View
    {
        $rate->load('facility.facilityType');

        return view('admin.rates.show', compact('rate'));
    }

    public function edit(Rate $rate): View
    {
        $rate->load('facility.facilityType');
        $facilities = Facility::with('facilityType')->where('is_active', true)->orderBy('name')->get();

        return view('admin.rates.edit', compact('rate', 'facilities'));
    }

    public function store(SaveRateRequest $request, Facility $facility): RedirectResponse
    {
        $data = $request->validated();
        $data['facility_id'] = $facility->id;
        $data['type'] = RateType::from($data['type']);

        try {
            $this->service->createRate($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.facilities.show', $facility)
            ->with('success', 'Rate added.');
    }

    public function update(SaveRateRequest $request, Rate $rate): RedirectResponse
    {
        $data = $request->validated();
        $data['type'] = RateType::from($data['type']);

        try {
            $this->service->updateRate($request->user(), $rate, $data);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.rates.show', $rate)
            ->with('success', 'Rate updated.');
    }

    public function activate(Request $request, Rate $rate): RedirectResponse
    {
        $this->service->activateRate($request->user(), $rate);

        return redirect()->back()
            ->with('success', "Rate '{$rate->name}' activated.");
    }

    public function deactivate(Request $request, Rate $rate): RedirectResponse
    {
        $this->service->deactivateRate($request->user(), $rate);

        return redirect()->back()
            ->with('success', "Rate '{$rate->name}' deactivated. Historical reservations are not affected.");
    }
}
