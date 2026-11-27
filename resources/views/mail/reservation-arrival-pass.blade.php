<x-mail::message>
# Your booking details

Hello {{ $guestName }},

{{ $intro }}

<x-mail::panel>
**Reservation code**

{{ $code }}
</x-mail::panel>

**Visit details**  
Booked space: {{ $facility }}  
Check-in: {{ $checkIn }}  
Check-out: {{ $checkOut }}  
Guests: {{ $guestCount }}  
@if($earlyHours > 0)
Early check-in: {{ $earlyHours }} hour(s)  
@endif
@if($lateHours > 0)
Late check-out: {{ $lateHours }} hour(s)  
@endif
Status: {{ $status }}  
Total: {{ $totalPrice }}  
Payments we’ve verified: {{ $verifiedPaid ?? 'See your booking' }}  
Balance due at check-in: {{ $remainingBalance ?? 'See your booking' }}  


@if($confirmedPolicy ?? false)
You can cancel and ask for a full refund of payments we’ve verified before you check in, even if you miss your visit. The resort owner approves refund requests. We record when your refund is sent. You can ask to change dates any time before check-in. We’ll check availability and share an updated price for you to accept.
@endif

@if(filled($specialRequests))
**Special requests**  
{{ $specialRequests }}
@endif

Show this email at the front desk when you arrive. Our team can find your booking using the last six characters of your reservation code.

@if($showPass)
<x-mail::button :url="$passUrl">
Open check-in pass
</x-mail::button>
@endif

@if($resortPhone)
Questions? Call us at [{{ $resortPhone }}]({{ $resortPhoneHref }}).
@endif

The {{ config('resort.short_name') }} team
</x-mail::message>
