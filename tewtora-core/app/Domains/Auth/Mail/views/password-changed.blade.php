{{--
    PasswordChanged — security notice after a reset. Hero illustration: an original
    "password updated" confirmation row drawn with tables.
--}}
@extends('shared-mail::layout')

@section('title', 'Your Tewtora password was changed')

@section('preheader', "Your password was changed on {$changedAtLabel}. If this wasn't you, act now.")

@section('content')
    @php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")

    @component('shared-mail::partials.hero', [
        'eyebrow' => 'Security notice',
        'headline' => 'Your password has been changed',
        'subline' => "You're all set, and for your security we've signed you out on every device.",
    ])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="44" valign="middle">
                    <div style="width:40px; height:40px; border-radius:20px; background-color:#E3F6EC; text-align:center; line-height:40px; font-family:{!! $font !!}; font-size:18px; font-weight:700; color:#1F9D61;">&#10003;</div>
                </td>
                <td valign="middle" style="padding-left:12px;">
                    <div style="font-family:{!! $font !!}; font-size:14px; font-weight:600; color:#111827;">Password updated</div>
                    <div style="font-family:{!! $font !!}; font-size:12px; color:#8A8FA3; padding-top:2px;">{{ $changedAtLabel }}</div>
                </td>
                <td align="right" valign="middle">
                    <span style="display:inline-block; white-space:nowrap; padding:6px 12px; border-radius:999px; background-color:#E3F6EC; font-family:{!! $font !!}; font-size:11px; font-weight:600; color:#1F9D61;">Secured</span>
                </td>
            </tr>
        </table>
    @endcomponent

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-body-pad" style="padding:40px 44px 44px;">
                <p style="margin:0; font-family:{!! $font !!}; font-size:18px; line-height:26px; font-weight:600; color:#111827;">
                    {{ $greeting }}
                </p>
                <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:#4B5163;">
                    This is a quick confirmation that the password for
                    <strong style="color:#111827;">{{ $email }}</strong> was changed on {{ $changedAtLabel }}.
                </p>
                <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:#4B5163;">
                    We've signed you out everywhere, so sign in again with your new password to pick up where you left off.
                </p>

                @include('shared-mail::partials.button', ['url' => $loginUrl, 'label' => 'Sign in to Tewtora'])

                {{-- The one case this email exists for. --}}
                {{-- div, not td: the layout's border-collapse drops border-radius on bordered cells --}}
                <div style="margin-top:32px; background-color:#FFF4F2; border:1px solid #FBDCD6; border-radius:16px; padding:18px 20px; font-family:{!! $font !!}; font-size:13px; line-height:21px; color:#4B5163;">
                    <span style="font-weight:600; color:#B4372A;">Wasn't you?</span>
                    Someone may have access to your email. <a href="{{ $forgotUrl }}" target="_blank" style="color:#B4372A; font-weight:600; text-decoration:underline;">Reset your password now</a>
                    and reply to this email so our team can help secure your account.
                </div>

                @include('shared-mail::partials.signoff', ['line' => 'Stay safe,'])
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    You're receiving this security notice for
    <span style="color:#4B5163;">{{ $email }}</span>.<br>
    We send it whenever your password changes. It can't be turned off.
@endsection
