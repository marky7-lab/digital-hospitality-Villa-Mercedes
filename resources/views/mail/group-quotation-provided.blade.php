<x-mail::message>
# Your quote for group booking {{ $groupCode }}

Hello {{ $guestName }},

Thanks for planning your visit with us. Your quote is ready for group booking **{{ $groupCode }}**.

<x-mail::panel>
**Total price: {{ $quotedTotal }}**  
**50% deposit: {{ $depositRequired }}**
</x-mail::panel>

@if(filled($notes))
**Notes from the resort**  
{{ $notes }}
@endif

**What’s in your request**  
@foreach($lines as $line)
- **{{ $line['name'] }}**: {{ $line['schedule'] }} ({{ $line['guests'] }} guest(s))
@endforeach



@if($confirmedPolicy ?? false)
Open your quote to pay a deposit of at least 50% or pay in full online. Upload your payment receipt so our team can check it. We’ll review your booking before confirming it. Any remaining balance is due at check-in.

You can cancel and ask for a full refund of payments we’ve verified before you check in, even if you miss your visit. The resort owner approves refund requests. We record when your refund is sent. You can ask to change dates any time before check-in. We’ll check availability and share an updated price for you to accept.
@else
The prices and terms already agreed for your booking still apply. Please contact us if you’d like to review them before paying.
@endif

<x-mail::button :url="$url">
View quote and payment options
</x-mail::button>

@if($resortPhone)
Questions? Call us at [{{ $resortPhone }}]({{ $resortPhoneHref }}).
@endif

The {{ config('resort.short_name') }} team
</x-mail::message>
