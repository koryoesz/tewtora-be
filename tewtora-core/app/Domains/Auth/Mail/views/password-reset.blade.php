{{--
    PasswordResetLink. Hero illustration: an original mock "new password" field drawn
    with tables (no images/SVG — see shared-mail::layout).
--}}
@extends('shared-mail::layout')

@section('title', 'Reset your Tewtora password')

@section('preheader', "Use this link within {$expiresInMinutes} minutes to choose a new password.")

@section('content')
    @php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")

    @component('shared-mail::partials.hero', [
        'eyebrow' => 'Password reset',
        'headline' => "Let's get you back in",
        'subline' => 'We received a request to reset the password for your Tewtora account.',
    ])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td valign="middle" style="font-family:{!! $font !!}; font-size:12px; font-weight:600; color:#6B7085;">New password</td>
                <td align="right" valign="middle">
                    <span style="display:inline-block; white-space:nowrap; padding:5px 11px; border-radius:999px; background-color:#EEF0FF; font-family:{!! $font !!}; font-size:11px; font-weight:600; color:#5B6CFF;">Expires in {{ $expiresInMinutes }} min</span>
                </td>
            </tr>
        </table>
        {{-- div, not td: the layout's border-collapse drops border-radius on bordered cells --}}
        <div style="margin-top:10px; background-color:#F3F4F8; border:1px solid #E4E6EF; border-radius:12px; padding:13px 16px; font-family:{!! $font !!}; font-size:16px; line-height:20px; letter-spacing:4px; color:#111827; white-space:nowrap;">
            &#9679;&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;&#9679;<span style="display:inline-block; width:2px; height:18px; background-color:#5B6CFF; vertical-align:middle; margin-left:3px;"></span>
        </div>
    @endcomponent

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-body-pad" style="padding:40px 44px 44px;">
                <p style="margin:0; font-family:{!! $font !!}; font-size:18px; line-height:26px; font-weight:600; color:#111827;">
                    {{ $greeting }}
                </p>
                <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:#4B5163;">
                    Choose a new password using the button below. For your security, this link
                    expires in <strong style="color:#111827;">{{ $expiresInMinutes }} minutes</strong> and can only be used once.
                </p>

                @include('shared-mail::partials.button', ['url' => $resetUrl, 'label' => 'Reset your password'])

                {{-- Reassurance for the "I didn't ask for this" case --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px; background-color:#F6F7FF; border-radius:16px;">
                    <tr>
                        <td style="padding:18px 20px; font-family:{!! $font !!}; font-size:13px; line-height:21px; color:#4B5163;">
                            <span style="font-weight:600; color:#111827;">Didn't ask for this?</span>
                            You can safely ignore this email. Your password won't change unless someone opens the link above and chooses a new one.
                        </td>
                    </tr>
                </table>

                @include('shared-mail::partials.signoff', ['line' => 'Happy learning,'])
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    You're receiving this because a password reset was requested for
    <span style="color:#4B5163;">{{ $email }}</span>.<br>
    Never share this link. Tewtora will never ask you for your password.
@endsection
