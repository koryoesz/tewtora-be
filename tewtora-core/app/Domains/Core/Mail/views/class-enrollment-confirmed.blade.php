{{-- ClassEnrollmentConfirmed: receipt + schedule + join link, on the shared brand frame. --}}
@extends('shared-mail::layout')

@section('title', "You're in: {$classTitle}")

@section('preheader', $isFree ? "Your seat in {$classTitle} is confirmed." : "Payment received. Your seat in {$classTitle} is confirmed.")

@section('content')
    @php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")

    @component('shared-mail::partials.hero', [
        'eyebrow' => $isFree ? 'Seat confirmed' : 'Payment received',
        'headline' => "You're in!",
        'subline' => "Your seat in {$classTitle} is confirmed. Here's everything you need.",
    ])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td valign="middle">
                    <div style="font-family:{!! $font !!}; font-size:14px; font-weight:600; color:{{ $theme['text'] }};">{{ $classTitle }}</div>
                    <div style="font-family:{!! $font !!}; font-size:12px; color:{{ $theme['muted'] }}; padding-top:2px;">
                        {{ count($sessionLabels) }} {{ \Illuminate\Support\Str::plural('session', count($sessionLabels)) }}
                        @if ($reference) &middot; Ref {{ $reference }} @endif
                    </div>
                </td>
                <td align="right" valign="middle">
                    <span style="display:inline-block; white-space:nowrap; padding:6px 12px; border-radius:999px; background-color:{{ $theme['tint'] }}; font-family:{!! $font !!}; font-size:12px; font-weight:700; color:{{ $theme['brandInk'] }};">{{ $isFree ? 'Free' : $amountLabel.' paid' }}</span>
                </td>
            </tr>
        </table>
    @endcomponent

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-body-pad" style="padding:40px 44px 44px;">
                <p style="margin:0; font-family:{!! $font !!}; font-size:18px; line-height:26px; font-weight:600; color:{{ $theme['text'] }};">
                    Hi there,
                </p>
                <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:{{ $theme['body'] }};">
                    @if ($isFree)
                        Your seat is confirmed. We can't wait to see you in class.
                    @else
                        We've received your payment of <strong style="color:{{ $theme['text'] }};">{{ $amountLabel }}</strong> and your seat is confirmed. Keep this email as your receipt.
                    @endif
                </p>

                <div style="margin:32px 0 0; font-family:{!! $font !!}; font-size:12px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:{{ $theme['muted'] }};">
                    Class days ({{ $timezoneLabel }})
                </div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px; background-color:{{ $theme['soft'] }}; border-radius:16px;">
                    @foreach ($sessionLabels as $i => $label)
                        <tr>
                            <td width="40" valign="middle" style="padding:{{ $i === 0 ? '16px' : '8px' }} 0 {{ $loop->last ? '16px' : '8px' }} 18px;">
                                <div style="width:26px; height:26px; border-radius:13px; background-color:{{ $theme['white'] }}; border:1px solid {{ $theme['ring'] }}; text-align:center; line-height:26px; font-family:{!! $font !!}; font-size:12px; font-weight:700; color:{{ $theme['brandInk'] }};">{{ $i + 1 }}</div>
                            </td>
                            <td valign="middle" style="padding:{{ $i === 0 ? '16px' : '8px' }} 18px {{ $loop->last ? '16px' : '8px' }} 10px; font-family:{!! $font !!}; font-size:14px; line-height:20px; color:{{ $theme['text'] }};">
                                {{ $label }}
                            </td>
                        </tr>
                    @endforeach
                </table>

                @include('shared-mail::partials.button', ['url' => $classUrl, 'label' => 'Go to your class'])

                <p style="margin:24px 0 0; font-family:{!! $font !!}; font-size:13px; line-height:21px; color:{{ $theme['body'] }}; text-align:center;">
                    Each class day's meeting link appears on the class page shortly before it starts,
                    and the Join button opens 10 minutes before class.
                </p>

                @include('shared-mail::partials.signoff', ['line' => 'Happy learning,'])
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Class links are for enrolled learners only. Please don't share them.
@endsection
