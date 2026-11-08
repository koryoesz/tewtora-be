{{-- ClassCancelled: sent to families with a confirmed seat when an admin cancels a class. --}}
@extends('shared-mail::layout')

@section('title', "Class cancelled: {$classTitle}")

@section('preheader', "We're sorry: {$classTitle} has been cancelled.")

@section('content')
    @php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")

    @component('shared-mail::partials.hero', [
        'eyebrow' => 'Class update',
        'headline' => 'This class has been cancelled',
        'subline' => "We're sorry, {$classTitle} won't be going ahead.",
    ])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td valign="middle" style="font-family:{!! $font !!}; font-size:14px; font-weight:600; color:{{ $theme['text'] }};">{{ $classTitle }}</td>
                <td align="right" valign="middle">
                    <span style="display:inline-block; white-space:nowrap; padding:6px 12px; border-radius:999px; background-color:{{ $theme['dangerBg'] }}; font-family:{!! $font !!}; font-size:11px; font-weight:600; color:{{ $theme['danger'] }};">Cancelled</span>
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
                    We're sorry to let you know that <strong style="color:{{ $theme['text'] }};">{{ $classTitle }}</strong> has been cancelled.
                </p>

                @if ($reason)
                    <div style="margin-top:20px; background-color:{{ $theme['soft'] }}; border-radius:16px; padding:18px 20px; font-family:{!! $font !!}; font-size:14px; line-height:22px; color:{{ $theme['body'] }};">
                        <span style="font-weight:600; color:{{ $theme['text'] }};">Reason:</span> {{ $reason }}
                    </div>
                @endif

                @if ($wasPaid)
                    <p style="margin:20px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:{{ $theme['body'] }};">
                        You paid <strong style="color:{{ $theme['text'] }};">{{ $amountLabel }}</strong> for this class. Our team will contact you about your payment. You can also reply to this email.
                    </p>
                @endif

                @include('shared-mail::partials.button', ['url' => $browseUrl, 'label' => 'Browse other classes'])

                @include('shared-mail::partials.signoff', ['line' => 'With apologies,'])
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    You're receiving this because you had a seat in this class.
@endsection
