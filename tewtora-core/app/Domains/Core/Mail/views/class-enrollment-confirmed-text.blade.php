{{-- Plain-text part — raw output ({!! !!}) since nothing here is rendered as HTML. --}}
Hi there,

@if ($isFree)
Your seat in {!! $classTitle !!} is confirmed. We can't wait to see you in class.
@else
We've received your payment of {!! $amountLabel !!} and your seat in {!! $classTitle !!} is confirmed. Keep this email as your receipt.@if ($reference) Reference: {!! $reference !!}.@endif

@endif

CLASS DAYS (Lagos time)
@foreach ($sessionLabels as $i => $label)
{{ $i + 1 }}. {!! $label !!}
@endforeach

Go to your class: {!! $classUrl !!}
Each class day's meeting link appears there shortly before it starts, and the Join button opens 10 minutes before class.

Happy learning,
The Tewtora team

--
Class links are for enrolled learners only. Please don't share them.
