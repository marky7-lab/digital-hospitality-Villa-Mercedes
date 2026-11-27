<x-mail::message>
# We’ve checked your group deposit

Hello {{ $guestName }},

Thank you. We’ve checked and recorded your deposit of **{{ $amountPaid }}** for group booking **{{ $groupCode }}**.

<x-mail::panel>
**Payment Reference:** {{ $referenceNumber }}  
**Deposit received:** {{ $amountPaid }}  
**Remaining Balance:** {{ $remainingBalance }} (due at check-in)
</x-mail::panel>

**What’s in your group booking**  
@foreach($lines as $line)
- **{{ $line['name'] }}** ({{ $line['code'] }}): {{ $line['schedule'] }} · {{ $line['guests'] }} guest(s)
@endforeach

@if($approved ?? false)
Your group booking is confirmed. Show your booking code or check-in pass when you arrive.
@else
We’ve checked your payment. Our team still needs to check availability before confirming your group booking.
@endif

@if($confirmedPolicy ?? false)
You can cancel and ask for a full refund of payments we’ve verified before you check in, even if you miss your visit. The resort owner approves refunds. We record when your refund is sent. You can also ask to change your dates any time before check-in.
@else
The terms you agreed to when booking still apply. Please contact us if you have questions about a refund or a change of dates.
@endif

<x-mail::button :url="$url">
View group booking
</x-mail::button>

@if($resortPhone)
Questions? Call us at [{{ $resortPhone }}]({{ $resortPhoneHref }}).
@endif

The {{ config('resort.short_name') }} team
</x-mail::message>
