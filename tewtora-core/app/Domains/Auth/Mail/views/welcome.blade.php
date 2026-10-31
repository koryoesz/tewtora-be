{{--
    WelcomeToTewtora — sent once, right after POST /auth/register. $copy comes from
    WelcomeToTewtora::copy(), so the per-role wording lives in one place and this view
    only handles layout. Hero "illustration" is an original mini-dashboard drawn with
    tables (no images, no SVG — Gmail strips SVG and many clients block images by default).
--}}
@extends('shared-mail::layout')

@section('title', $copy['subject'])

@section('preheader', $copy['preheader'])

@section('content')
    @php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")

    @component('shared-mail::partials.hero', ['eyebrow' => $copy['eyebrow'], 'headline' => $copy['headline'], 'subline' => $copy['subline']])
        {{-- Mini dashboard card: who it's for, and onboarding progress --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td width="44" valign="middle">
                    <div style="width:40px; height:40px; border-radius:20px; background-color:{{ $theme['tint'] }}; text-align:center; line-height:40px; font-family:{!! $font !!}; font-size:16px; font-weight:700; color:{{ $theme['brandInk'] }};">{{ $initial }}</div>
                </td>
                <td valign="middle" style="padding-left:12px;">
                    <div style="font-family:{!! $font !!}; font-size:14px; font-weight:600; color:{{ $theme['text'] }};">{{ $copy['card_title'] }}</div>
                    <div style="font-family:{!! $font !!}; font-size:12px; color:{{ $theme['muted'] }}; padding-top:2px;">{{ $copy['card_caption'] }}</div>
                </td>
                <td align="right" valign="middle">
                    <span style="display:inline-block; white-space:nowrap; padding:6px 12px; border-radius:999px; background-color:{{ $theme['tint'] }}; font-family:{!! $font !!}; font-size:11px; font-weight:600; color:{{ $theme['brandInk'] }};">{{ $copy['card_badge'] }}</span>
                </td>
            </tr>
        </table>

        {{-- Progress bars: one per onboarding step, first one "done" --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
            <tr>
                <td width="33%" style="padding-right:6px;"><div style="height:8px; border-radius:4px; background-color:{{ $theme['brand'] }}; font-size:0; line-height:0;">&nbsp;</div></td>
                <td width="33%" style="padding:0 3px;"><div style="height:8px; border-radius:4px; background-color:{{ $theme['trackMid'] }}; font-size:0; line-height:0;">&nbsp;</div></td>
                <td width="33%" style="padding-left:6px;"><div style="height:8px; border-radius:4px; background-color:{{ $theme['track'] }}; font-size:0; line-height:0;">&nbsp;</div></td>
            </tr>
        </table>
    @endcomponent

    {{-- Body --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-body-pad" style="padding:40px 44px 44px;">
                <p style="margin:0; font-family:{!! $font !!}; font-size:18px; line-height:26px; font-weight:600; color:{{ $theme['text'] }};">
                    {{ $greeting }}
                </p>
                @foreach ($copy['intro'] as $paragraph)
                    <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:{{ $theme['body'] }};">
                        {{ $paragraph }}
                    </p>
                @endforeach

                {{-- Next steps --}}
                <div style="margin:32px 0 0; font-family:{!! $font !!}; font-size:12px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:{{ $theme['muted'] }};">
                    {{ $copy['steps_heading'] }}
                </div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                    @foreach ($copy['steps'] as $i => $step)
                        <tr>
                            <td style="padding:{{ $i === 0 ? '0' : '10px' }} 0 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $theme['soft'] }}; border-radius:16px;">
                                    <tr>
                                        <td width="36" valign="top" style="padding:18px 0 18px 18px;">
                                            <div style="width:30px; height:30px; border-radius:15px; background-color:{{ $i === 0 ? $theme['brand'] : $theme['white'] }}; border:1px solid {{ $i === 0 ? $theme['brand'] : $theme['ring'] }}; text-align:center; line-height:30px; font-family:{!! $font !!}; font-size:13px; font-weight:700; color:{{ $i === 0 ? $theme['white'] : $theme['brandInk'] }};">{{ $i + 1 }}</div>
                                        </td>
                                        <td valign="top" style="padding:18px 18px 18px 14px;">
                                            <div style="font-family:{!! $font !!}; font-size:15px; line-height:22px; font-weight:600; color:{{ $theme['text'] }};">{{ $step['title'] }}</div>
                                            <div style="padding-top:3px; font-family:{!! $font !!}; font-size:13px; line-height:20px; color:{{ $theme['mutedStrong'] }};">{{ $step['body'] }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </table>

                @include('shared-mail::partials.button', ['url' => $dashboardUrl, 'label' => $copy['cta']])

                @include('shared-mail::partials.signoff', ['line' => $copy['signoff']])
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    You're receiving this because a Tewtora account was just created with
    <span style="color:{{ $theme['body'] }};">{{ $email }}</span>.<br>
    If that wasn't you, simply reply to this email and we'll sort it out.
@endsection
