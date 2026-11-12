{{-- Plain-text part — raw output ({!! !!}) since nothing here is rendered as HTML. --}}
Hi there,

We're sorry to let you know that {!! $classTitle !!} has been cancelled.
@if ($reason)

Reason: {!! $reason !!}
@endif
@if ($wasPaid)

You paid {!! $amountLabel !!} for this class. Our team will contact you about your payment. You can also reply to this email.
@endif

Browse other classes: {!! $browseUrl !!}

With apologies,
The Tewtora team
