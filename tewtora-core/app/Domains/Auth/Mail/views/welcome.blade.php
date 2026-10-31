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

    {{-- Hero panel --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-hero tw-hero-pad" style="background-color:#5B6CFF; background-image:linear-gradient(160deg, #6B7BFF 0%, #5B6CFF 55%, #4F5DF0 100%); border-radius:22px; padding:44px 44px 0;">
                <div style="font-family:{!! $font !!}; font-size:12px; font-weight:600; letter-spacing:1.6px; text-transform:uppercase; color:#D9DDFF;">
                    {{ $copy['eyebrow'] }}
                </div>
                <h1 class="tw-hero-title" style="margin:12px 0 0; font-family:{!! $font !!}; font-size:34px; line-height:40px; font-weight:700; letter-spacing:-0.6px; color:#FFFFFF;">
                    {{ $copy['headline'] }}
                </h1>
                <p style="margin:14px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:24px; color:#E4E7FF;">
                    {{ $copy['subline'] }}
                </p>

                {{-- Mini dashboard card, sitting on the bottom edge of the panel --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;">
                    <tr>
                        <td style="background-color:#FFFFFF; border-radius:18px 18px 0 0; padding:20px 22px 22px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="44" valign="middle">
                                        <div style="width:40px; height:40px; border-radius:20px; background-color:#EEF0FF; text-align:center; line-height:40px; font-family:{!! $font !!}; font-size:16px; font-weight:700; color:#5B6CFF;">{{ $initial }}</div>
                                    </td>
                                    <td valign="middle" style="padding-left:12px;">
                                        <div style="font-family:{!! $font !!}; font-size:14px; font-weight:600; color:#111827;">{{ $copy['card_title'] }}</div>
                                        <div style="font-family:{!! $font !!}; font-size:12px; color:#8A8FA3; padding-top:2px;">{{ $copy['card_caption'] }}</div>
                                    </td>
                                    <td align="right" valign="middle">
                                        <span style="display:inline-block; white-space:nowrap; padding:6px 12px; border-radius:999px; background-color:#EEF0FF; font-family:{!! $font !!}; font-size:11px; font-weight:600; color:#5B6CFF;">{{ $copy['card_badge'] }}</span>
                                    </td>
                                </tr>
                            </table>

                            {{-- Progress bars: one per onboarding step, first one "done" --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
                                <tr>
                                    <td width="33%" style="padding-right:6px;"><div style="height:8px; border-radius:4px; background-color:#5B6CFF; font-size:0; line-height:0;">&nbsp;</div></td>
                                    <td width="33%" style="padding:0 3px;"><div style="height:8px; border-radius:4px; background-color:#C9CFFF; font-size:0; line-height:0;">&nbsp;</div></td>
                                    <td width="33%" style="padding-left:6px;"><div style="height:8px; border-radius:4px; background-color:#E6E9FF; font-size:0; line-height:0;">&nbsp;</div></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Body --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="tw-body-pad" style="padding:40px 44px 44px;">
                <p style="margin:0; font-family:{!! $font !!}; font-size:18px; line-height:26px; font-weight:600; color:#111827;">
                    {{ $greeting }}
                </p>
                @foreach ($copy['intro'] as $paragraph)
                    <p style="margin:12px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:25px; color:#4B5163;">
                        {{ $paragraph }}
                    </p>
                @endforeach

                {{-- Next steps --}}
                <div style="margin:32px 0 0; font-family:{!! $font !!}; font-size:12px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:#8A8FA3;">
                    {{ $copy['steps_heading'] }}
                </div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                    @foreach ($copy['steps'] as $i => $step)
                        <tr>
                            <td style="padding:{{ $i === 0 ? '0' : '10px' }} 0 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F6F7FF; border-radius:16px;">
                                    <tr>
                                        <td width="36" valign="top" style="padding:18px 0 18px 18px;">
                                            <div style="width:30px; height:30px; border-radius:15px; background-color:{{ $i === 0 ? '#5B6CFF' : '#FFFFFF' }}; border:1px solid {{ $i === 0 ? '#5B6CFF' : '#DADFFF' }}; text-align:center; line-height:30px; font-family:{!! $font !!}; font-size:13px; font-weight:700; color:{{ $i === 0 ? '#FFFFFF' : '#5B6CFF' }};">{{ $i + 1 }}</div>
                                        </td>
                                        <td valign="top" style="padding:18px 18px 18px 14px;">
                                            <div style="font-family:{!! $font !!}; font-size:15px; line-height:22px; font-weight:600; color:#111827;">{{ $step['title'] }}</div>
                                            <div style="padding-top:3px; font-family:{!! $font !!}; font-size:13px; line-height:20px; color:#6B7085;">{{ $step['body'] }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </table>

                {{-- Primary action: bulletproof button (VML for Outlook, styled link elsewhere) --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:36px;">
                    <tr>
                        <td align="center">
                            <!--[if mso]>
                            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $dashboardUrl }}" style="height:56px;v-text-anchor:middle;width:504px;" arcsize="22%" stroke="f" fillcolor="#5B6CFF">
                                <w:anchorlock/>
                                <center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $copy['cta'] }}</center>
                            </v:roundrect>
                            <![endif]-->
                            <!--[if !mso]><!-->
                            <a href="{{ $dashboardUrl }}" target="_blank" style="display:block; background-color:#5B6CFF; border-radius:12px; padding:17px 24px; font-family:{!! $font !!}; font-size:16px; line-height:22px; font-weight:600; color:#FFFFFF; text-align:center; text-decoration:none; box-shadow:0 8px 20px rgba(91, 108, 255, 0.28);">
                                {{ $copy['cta'] }} &rarr;
                            </a>
                            <!--<![endif]-->
                        </td>
                    </tr>
                </table>

                <p style="margin:16px 0 0; font-family:{!! $font !!}; font-size:12px; line-height:19px; color:#8A8FA3; text-align:center;">
                    Button not working? Paste this into your browser:<br>
                    <a href="{{ $dashboardUrl }}" target="_blank" style="color:#5B6CFF; text-decoration:none; word-break:break-all;">{{ $dashboardUrl }}</a>
                </p>

                {{-- Sign-off --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:36px; border-top:1px solid #ECEEF5;">
                    <tr>
                        <td style="padding-top:24px; font-family:{!! $font !!}; font-size:14px; line-height:22px; color:#4B5163;">
                            {{ $copy['signoff'] }}<br>
                            <span style="font-weight:600; color:#111827;">The Tewtora team</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    You're receiving this because a Tewtora account was just created with
    <span style="color:#4B5163;">{{ $email }}</span>.<br>
    If that wasn't you, simply reply to this email and we'll sort it out.
@endsection
