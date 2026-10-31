{{--
    Periwinkle hero panel at the top of the card. Use via
    @component('shared-mail::partials.hero', ['eyebrow' => …, 'headline' => …, 'subline' => …])
        …illustration, drawn with tables (no images/SVG — see layout.blade.php)…
    @endcomponent
    The slot sits on the panel's bottom edge, so start it with a white card
    whose top corners are rounded (border-radius:18px 18px 0 0).
--}}
@php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td class="tw-hero tw-hero-pad" style="background-color:#5B6CFF; background-image:linear-gradient(160deg, #6B7BFF 0%, #5B6CFF 55%, #4F5DF0 100%); border-radius:22px; padding:44px 44px 0;">
            <div style="font-family:{!! $font !!}; font-size:12px; font-weight:600; letter-spacing:1.6px; text-transform:uppercase; color:#D9DDFF;">
                {{ $eyebrow }}
            </div>
            <h1 class="tw-hero-title" style="margin:12px 0 0; font-family:{!! $font !!}; font-size:34px; line-height:40px; font-weight:700; letter-spacing:-0.6px; color:#FFFFFF;">
                {{ $headline }}
            </h1>
            <p style="margin:14px 0 0; font-family:{!! $font !!}; font-size:15px; line-height:24px; color:#E4E7FF;">
                {{ $subline }}
            </p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;">
                <tr>
                    <td style="background-color:#FFFFFF; border-radius:18px 18px 0 0; padding:20px 22px 22px;">
                        {{ $slot }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
