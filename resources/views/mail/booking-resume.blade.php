<x-mail::message>
# Finish your booking

Hello {{ $guestName }},

You can pick up where you left off. We saved the dates, guest count, and any names you entered. Your dates are still open for others to book.

<x-mail::panel>
**{{ $facility }}**

Check in: {{ $checkIn }}  
Check out: {{ $checkOut }}  
Guests: {{ $guestCount }}
</x-mail::panel>

<x-mail::button :url="$url">
Finish my booking
</x-mail::button>

This link works for {{ $expireHours }} hours. When you open it, you’ll see the latest price and availability. If your dates are taken, we’ll show you other dates you can book.

@if($resortPhone)
Questions? Call us at [{{ $resortPhone }}]({{ $resortPhoneHref }}).
@endif

The {{ config('resort.short_name') }} team
</x-mail::message>
