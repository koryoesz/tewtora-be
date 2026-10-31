{{-- Plain-text part — raw output ({!! !!}) since nothing here is rendered as HTML. --}}
{!! $greeting !!}

@foreach ($copy['intro'] as $paragraph)
{!! $paragraph !!}

@endforeach
{!! strtoupper($copy['steps_heading']) !!}

@foreach ($copy['steps'] as $i => $step)
{{ $i + 1 }}. {!! $step['title'] !!} — {!! $step['body'] !!}
@endforeach

{!! $copy['cta'] !!}: {!! $dashboardUrl !!}

{!! $copy['signoff'] !!}
The Tewtora team

--
You're receiving this because a Tewtora account was just created with {!! $email !!}. If that wasn't you, simply reply to this email and we'll sort it out.
