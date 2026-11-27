<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FacilityStatus;
use App\Enums\FacilityType;
use App\Exceptions\Admin\PhotoLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacilityRequest;
use App\Http\Requests\Admin\UpdateFacilityRequest;
use App\Models\Amenity;
use App\Models\Facility;
use App\Models\FacilityType as FacilityTypeModel;
use App\Models\GalleryImage;
use App\Services\Admin\FacilityManagementService;
use App\Support\LiveFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminFacilityController extends Controller
{
    public function __construct(
        private readonly FacilityManagementService $service
    ) {}

    public function index(Request $request): View
    {
        $query = Facility::with([
            'facilityType',
            'amenities',
            'galleryImages' => fn ($query) => $query->where('is_active', true),
        ]);

        // Server-side type filter
        if ($request->filled('type')) {
            $typeModel = FacilityTypeModel::where('slug', $request->input('type'))->first();
            if ($typeModel) {
                $query->where('facility_type_id', $typeModel->id);
            }
        }

        // Server-side status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Server-side active filter
        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by name, slug, or facility type
        if ($request->filled('search')) {
            $like = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($searchQuery) use ($like) {
                $searchQuery->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('facilityType', function ($typeQuery) use ($like) {
                        $typeQuery->where('name', 'like', $like)
                            ->orWhere('slug', 'like', $like);
                    });
            });
        }

        $facilities = $query->orderBy('sort_order')->orderBy('name')->paginate(20);

        if (LiveFilter::wantsPartial($request)) {
            return view('admin.facilities.partials.results', compact('facilities'));
        }

        $facilityTypes = FacilityTypeModel::where('is_active', true)->get();

        return view('admin.facilities.index', compact('facilities', 'facilityTypes'));
    }

    public function create(): View
    {
        $facilityTypes = FacilityTypeModel::where('is_active', true)->get();

        return view('admin.facilities.create', compact('facilityTypes'));
    }

    public function show(Facility $facility): View
    {
        $facility->load(['facilityType', 'amenities', 'rates', 'galleryImages', 'packageComponents.componentFacility']);

        $allAmenities = Amenity::where('is_active', true)->orderBy('name')->get();

        // For packages: get non-package facilities available as components
        $availableComponents = collect();
        $packageType = FacilityTypeModel::where('slug', FacilityType::ResortPackage->value)->first();
        $isPackage = $packageType && $facility->facility_type_id === $packageType->id;

        if ($isPackage) {
            $existingComponentIds = $facility->packageComponents->pluck('component_facility_id')->toArray();
            $availableComponents = Facility::where('id', '!=', $facility->id)
                ->where('facility_type_id', '!=', $packageType->id)
                ->where('is_active', true)
                ->whereNotIn('id', $existingComponentIds)
                ->orderBy('name')
                ->get();
        }

        return view('admin.facilities.show', compact(
            'facility', 'allAmenities', 'availableComponents', 'isPackage'
        ));
    }

    public function edit(Facility $facility): View
    {
        $facility->load(['galleryImages', 'facilityType']);
        $facilityTypes = FacilityTypeModel::where('is_active', true)->get();

        return view('admin.facilities.edit', compact('facility', 'facilityTypes'));
    }

    public function store(StoreFacilityRequest $request): RedirectResponse
    {
        try {
            $facility = $this->service->createFacility($request->user(), $request->validated());
        } catch (PhotoLimitExceededException $e) {
            return redirect()->back()->withInput()->withErrors(['images' => $e->getMessage()]);
        }

        return redirect()->route('admin.facilities.show', $facility)
            ->with('success', 'Space added.');
    }

    public function update(UpdateFacilityRequest $request, Facility $facility): RedirectResponse
    {
        try {
            $this->service->updateFacility($request->user(), $facility, $request->validated());
        } catch (PhotoLimitExceededException $e) {
            return redirect()->back()->withInput()->withErrors(['images' => $e->getMessage()]);
        }

        $photoCount = $facility->fresh()->galleryImages()->count();
        $message = 'Facility updated successfully.';

        if ($photoCount < GalleryImage::RECOMMENDED_COUNT) {
            $needed = GalleryImage::RECOMMENDED_COUNT - $photoCount;
            $message .= " Add {$needed} more photo(s) so guests can see different angles of this space.";
        }

        return redirect()->route('admin.facilities.show', $facility)
            ->with('success', $message);
    }

    public function changeStatus(Request $request, Facility $facility): RedirectResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', FacilityStatus::values())],
        ]);

        $status = FacilityStatus::from($request->input('status'));
        $this->service->changeStatus($request->user(), $facility, $status);

        return redirect()->back()
            ->with('success', "Facility status changed to {$status->label()}.");
    }

    public function syncAmenities(Request $request, Facility $facility): RedirectResponse
    {
        $request->validate([
            'amenities' => ['present', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
        ]);

        $this->service->assignAmenities(
            $request->user(),
            $facility,
            $request->input('amenities', [])
        );

        return redirect()->back()
            ->with('success', 'Amenities updated.');
    }

    public function syncComponents(Request $request, Facility $facility): RedirectResponse
    {
        $request->validate([
            'components' => ['present', 'array'],
            'components.*' => ['integer', 'exists:facilities,id'],
        ]);

        try {
            $this->service->syncComponents(
                $request->user(),
                $facility,
                $request->input('components', [])
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Package details updated.');
    }

    public function destroy(Request $request, Facility $facility): RedirectResponse
    {
        $this->service->deleteFacility($request->user(), $facility);

        return redirect()->route('admin.facilities.index')
            ->with('success', 'Space archived.');
    }

    public function destroyImage(Request $request, Facility $facility, GalleryImage $image): RedirectResponse
    {
        try {
            $this->service->deleteImage($request->user(), $facility, $image);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()
            ->with('success', 'Image deleted.');
    }
}
