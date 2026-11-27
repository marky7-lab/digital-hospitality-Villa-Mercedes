@props(['reservation', 'recordView' => null])

@php
    // A record's pages, declared once. Before this, "Deposit & payment history"
    // carried a hand-written "Back to reservation" link and the reservation
    // page carried a "View payment history" button, so the two halves of one
    // booking each knew a little about the other. A guest reading their payment
    // history could not see the arrival pass at all.
    //
    // Three tabs, because a guest asks three questions: what did I book, what do
    // I do when I arrive, what do I owe. The printable pass is a document, not a
    // fourth question — it is linked from Arrival, where a guest needs it.
    $active = $recordView ?? \App\Enums\ReservationRecordView::fromQuery(request()->query('view'));
    $onPayments = request()->routeIs('customer.payments.*');
    $listContext = request()->only('tab', 'page');

    $tabs = collect(\App\Enums\ReservationRecordView::cases())
        ->map(fn ($facet) => [
            'label' => $facet->label(),
            'href' => url()->query($facet->url($reservation), $listContext),
            'active' => ! $onPayments && $active === $facet,
        ])
        ->push([
            'label' => 'Payments',
            'href' => route('customer.payments.index', [$reservation, ...$listContext]),
            'active' => $onPayments,
        ]);
@endphp

<nav class="inline-flex max-w-full flex-wrap items-center gap-x-1 border-b border-border"
     aria-label="This reservation">
    @foreach($tabs as $tab)
        <a href="{{ $tab['href'] }}"
           @if($tab['active']) aria-current="page" @endif
           @class([
               'inline-flex min-h-11 items-center border-b-2 px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1 -mb-px',
               'border-primary text-primary' => $tab['active'],
               'border-transparent text-text-muted hover:border-accent/50 hover:text-text' => ! $tab['active'],
           ])>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
