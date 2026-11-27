<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservation\InvalidReservationTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RescheduleReservationRequest;
use App\Http\Requests\Admin\StoreWalkInReservationRequest;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\Reservation;
use App\Models\SwimmingSession;
use App\Services\CottageBookingService;
use App\Services\PricingService;
use App\Services\ReservationService;
use App\Support\AdminCatalogCache;
use App\Support\GuestInformation;
use App\Support\LiveFilter;
use App\Support\ResortTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdminReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $service,
        private readonly PricingService $pricingService,
        private readonly CottageBookingService $cottageBookingService
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        // The walk-in sheet used to live in a modal on this screen. Old
        // bookmarks and the header shortcut land on the dedicated page now.
        if ($request->boolean('walk_in')) {
            return redirect()->route('admin.reservations.create');
        }

        $query = Reservation::with(['user', 'facility.facilityType', 'reservationGroup']);

        // Filter by Status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by Source
        if ($request->filled('source')) {
            $query->where('reservation_source', $request->input('source'));
        }

        // Filter by Facility Type
        if ($request->filled('facility_type')) {
            $typeModel = FacilityType::where('slug', $request->input('facility_type'))->first();
            if ($typeModel) {
                $query->whereHas('facility', fn ($q) => $q->where('facility_type_id', $typeModel->id));
            }
        }

        // Filter by Facility
        if ($request->filled('facility_id')) {
            $query->where('facility_id', $request->input('facility_id'));
        }

        // Filter by Dates (Check-in or Check-out overlaps with selected period)
        if ($request->filled('date_from')) {
            $query->where('check_out', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_until')) {
            $query->where('check_in', '<=', $request->input('date_until'));
        }

        if ($request->filled('search')) {
            $query->matchingGuestLookup((string) $request->input('search'));
        }

        // The id tiebreaker is what makes this page-safe. created_at is stored to
        // the second, so a batch of bookings filed in the same second — a busy
        // morning, a walk-in rush, a seeder — is a tie, and MySQL may order a tie
        // differently on each page request. Without a total order a reservation
        // can appear twice, or vanish between page 1 and page 2.
        $arrivalDay = $request->boolean('arriving_today') ? ResortTime::now() : null;
        if ($arrivalDay) {
            $query->arrivingOn($arrivalDay)->reorder();
        }

        $bookingIds = $query->selectRaw('MAX(reservations.id)')
            ->groupBy('reservation_group_id')
            ->groupByRaw('CASE WHEN reservation_group_id IS NULL THEN reservations.id ELSE 0 END');
        $results = Reservation::with([
            'user', 'facility.facilityType', 'payments',
            'reservationGroup.directPayments' => fn ($payments) => $payments->where('status', 'pending')->where('source', 'customer_deposit')->orderBy('id'),
            'reservationGroup.reservations' => fn ($lines) => $lines
                ->when($arrivalDay, fn ($query) => $query->arrivingOn($arrivalDay))
                ->with('facility.facilityType'),
        ])->whereIn('id', $bookingIds);
        if ($arrivalDay) {
            $results->orderBy('check_in')->orderBy('id');
        } else {
            $results->orderByDesc('created_at')->orderByDesc('id');
        }
        $reservations = $results->paginate(20);

        if (LiveFilter::wantsPartial($request)) {
            return view('admin.reservations.partials.results', compact('reservations'));
        }

        $facilityTypes = FacilityType::where('is_active', true)->get();
        $filterFacilities = Facility::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.reservations.index', compact(
            'reservations',
            'facilityTypes',
            'filterFacilities'
        ));
    }

    public function create(): View
    {
        return view('admin.reservations.create', [
            'facilities' => AdminCatalogCache::bookableFacilities(),
        ]);
    }

    public function show(Reservation $reservation): View
    {
        // The scheduler remains the primary checkout mechanism. Reconcile this
        // record as it is opened as well, so a stopped local scheduler cannot
        // leave an ended stay looking active or offer an "early" checkout.
        if ($reservation->isInHouse() && $reservation->occupancyEnd()->lte(ResortTime::databaseNow())) {
            $this->service->checkOutAutomatically($reservation);
            $reservation->refresh();
        }

        $reservation->load(['user', 'facility.facilityType', 'payments', 'activities.causer']);

        $facilities = AdminCatalogCache::bookableFacilities();

        $swimmingSessions = SwimmingSession::where('is_active', true)->orderBy('sort_order')->get();

        return view('admin.reservations.show', compact('reservation', 'facilities', 'swimmingSessions'));
    }

    public function approve(Request $request, Reservation $reservation): RedirectResponse
    {
        try {
            $this->service->changeStatus($reservation, ReservationStatus::Approved, $request->user());
        } catch (InvalidReservationTransitionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Booking approved.');
    }

    public function reject(Request $request, Reservation $reservation): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->service->changeStatus($reservation, ReservationStatus::Rejected, $request->user(), $request->input('reason'));
        } catch (InvalidReservationTransitionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Booking request declined.');
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->cancel($reservation, $request->user(), $request->input('reason'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Booking cancelled.');
    }

    public function confirmWithdrawal(Request $request, Reservation $reservation): RedirectResponse
    {
        try {
            $this->service->confirmWithdrawal($reservation, $request->user());
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Withdrawal confirmed. The reservation is cancelled.');
    }

    public function calculateReschedule(RescheduleReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $facility = Facility::findOrFail($request->validated('facility_id'));
        $checkIn = Carbon::parse($request->validated('check_in'));
        $checkOut = Carbon::parse($request->validated('check_out'));
        $pax = $request->validated('guestCount');
        $earlyCheckInHours = (int) ($request->validated('early_check_in_hours') ?? 0);
        $lateCheckOutHours = (int) ($request->validated('late_check_out_hours') ?? 0);

        try {
            $breakdown = $facility->isCottage()
                ? $this->cottageBookingService->price($facility, $request->validated('swimming_session_id'), $checkIn, $checkOut)
                : $this->pricingService->calculate(
                    $facility,
                    $checkIn,
                    $checkOut,
                    $earlyCheckInHours,
                    $lateCheckOutHours,
                    $pax
                );

            return response()->json([
                'success' => true,
                'breakdown' => [
                    'base_amount' => $breakdown->baseAmount / 100,
                    'additional_charges' => ($breakdown->totalAmount - $breakdown->baseAmount) / 100,
                    'hall_extension_charge' => $breakdown->hallExtensionCharge / 100,
                    'total_amount' => $breakdown->totalAmount / 100,
                ],
                'facility_name' => $facility->name,
                'dates' => $checkIn->format('M d, Y g:i A').' - '.$checkOut->format('M d, Y g:i A'),
                'pax' => $pax,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function reschedule(RescheduleReservationRequest $request, Reservation $reservation): RedirectResponse
    {
        $facility = Facility::findOrFail($request->validated('facility_id'));

        try {
            $this->service->reschedule(
                $reservation,
                $facility,
                Carbon::parse($request->validated('check_in')),
                Carbon::parse($request->validated('check_out')),
                $request->user(),
                $request->validated('guestCount'),
                [
                    'early_check_in_hours' => (int) ($request->validated('early_check_in_hours') ?? 0),
                    'late_check_out_hours' => (int) ($request->validated('late_check_out_hours') ?? 0),
                    'swimming_session_id' => $request->validated('swimming_session_id'),
                ]
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Booking moved to the new dates.');
    }

    public function downloadGuestInformationSheet(Request $request, Reservation $reservation): Response
    {
        return app(\App\Services\GuestInformationSheetNumber::class)->issue(
            $reservation,
            fn (Reservation $issued) => $this->renderGuestInformationSheet($issued, $request->boolean('print'))
        );
    }

    private function renderGuestInformationSheet(Reservation $reservation, bool $inline = false): Response
    {
        abort_unless($reservation->status === ReservationStatus::Approved, 404);

        $reservation->load(['user', 'facility']);

        $guest = [
            'name' => $reservation->guestName(),
            'place_of_origin' => $reservation->guest_place_of_origin ?: $reservation->user?->address,
            'nationality' => $reservation->guest_nationality ?: $reservation->user?->nationality,
            'company' => $reservation->guest_company ?: $reservation->user?->company,
            'valid_id' => $reservation->guest_valid_id?->label() ?: $reservation->user?->valid_id_type?->label(),
            'gender' => $reservation->guest_gender?->label() ?: $reservation->user?->gender?->label(),
            'phone' => $reservation->guestPhone(),
            'email' => $reservation->guestEmail(),
        ];

        $pdf = Pdf::loadView('admin.reservations.guest-information-sheet', [
            'reservation' => $reservation,
            'guest' => $guest,
            'companions' => $reservation->otherGuestNames(),
            'logoDataUri' => $this->guestInformationSheetLogoDataUri(),
            'documentDetails' => [
                'location' => 'Barangay Namnama, City of Ilagan, Isabela',
                'contacts' => '09173268271/09272687279',
                'email' => 'villa.mercedes318@gmail.com',
                'accreditation' => 'Department of Tourism Accreditation No.DOT-R02-MAB-01664-2024',
            ],
        ])->setPaper('a4', 'portrait');

        $filename = 'guest-information-sheet-'.$reservation->reservation_code.'.pdf';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * Reuse the transparent mark in the printable sheet without writing a
     * second logo asset to disk. Its white pixels are recolored for white paper
     * while the source alpha channel, including antialiased edges, is retained.
     */
    private function guestInformationSheetLogoDataUri(): ?string
    {
        $logoPath = public_path('images/logo-villa-mercedes-mark.png');

        if (! file_exists($logoPath) || ! function_exists('imagecreatefrompng')) {
            return null;
        }

        $logo = imagecreatefrompng($logoPath);

        if ($logo === false) {
            return null;
        }

        imagealphablending($logo, false);
        imagesavealpha($logo, true);

        $colors = [];
        $width = imagesx($logo);
        $height = imagesy($logo);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($logo, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;

                if ($alpha === 127) {
                    continue;
                }

                $colors[$alpha] ??= imagecolorallocatealpha($logo, 112, 65, 64, $alpha);
                imagesetpixel($logo, $x, $y, $colors[$alpha]);
            }
        }

        ob_start();
        imagepng($logo);
        $png = ob_get_clean();
        imagedestroy($logo);

        return is_string($png) ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    public function checkIn(Request $request, Reservation $reservation): RedirectResponse
    {
        try {
            $this->service->checkIn($reservation, $request->user());
        } catch (InvalidReservationTransitionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Checked in {$reservation->guestName()} at {$reservation->facility->name}. The stay is now on the Checked-in list.");
    }

    public function checkOut(Request $request, Reservation $reservation): RedirectResponse
    {
        try {
            $this->service->checkOut($reservation, $request->user());
        } catch (InvalidReservationTransitionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Checked out {$reservation->guestName()} from {$reservation->facility->name}. Reservation {$reservation->reservation_code} is complete.");
    }

    public function complete(Request $request, Reservation $reservation): RedirectResponse
    {
        try {
            $this->service->changeStatus($reservation, ReservationStatus::Completed, $request->user());
        } catch (InvalidReservationTransitionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Reservation marked as completed.');
    }

    public function storeWalkIn(StoreWalkInReservationRequest $request): RedirectResponse
    {
        $facility = Facility::findOrFail($request->validated('facility_id'));

        try {
            $reservation = $this->service->createReservation(
                $facility,
                $request->user(),
                Carbon::parse($request->validated('check_in')),
                Carbon::parse($request->validated('check_out')),
                ReservationSource::WalkIn,
                $request->validated('guestCount'),
                [
                    'early_check_in_hours' => (int) ($request->validated('early_check_in_hours') ?? 0),
                    'late_check_out_hours' => (int) ($request->validated('late_check_out_hours') ?? 0),
                    'admin_notes' => $request->validated('admin_notes'),
                    ...GuestInformation::fromValidated($request->validated()),
                ]
            );
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.reservations.show', $reservation)
            ->with('success', 'Walk-in booking saved.');
    }
}
