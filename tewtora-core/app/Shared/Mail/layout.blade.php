{{--
    Brand frame shared by every domain's Mailables (backend-engineering-standards.md §5):
    tinted canvas, wordmark, one white rounded card, muted footer. Table-based with inline
    styles throughout — Gmail strips most <style> rules and Outlook ignores flex/grid, so
    anything that has to survive every client lives inline. Rounded corners and the soft
    glow degrade to square/flat in Outlook desktop, which is acceptable.

    Sections: preheader (inbox preview text), content (inside the card), footer_note.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', 'Tewtora')</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; line-height: 100%; outline: none; text-decoration: none; }
        a { color: {{ $theme['brandInk'] }}; }
        @media only screen and (max-width: 620px) {
            .tw-shell { width: 100% !important; }
            .tw-gutter { padding-left: 16px !important; padding-right: 16px !important; }
            .tw-card-pad { padding: 8px !important; }
            .tw-body-pad { padding: 28px 20px 32px !important; }
            .tw-hero-pad { padding: 32px 24px 0 !important; }
            .tw-hero-title { font-size: 28px !important; line-height: 34px !important; }
            .tw-card { border-radius: 20px !important; }
            .tw-hero { border-radius: 16px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:{{ $theme['canvas'] }};">
    {{-- Inbox preview line; the trailing spacer stops clients pulling body text into it. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $theme['canvas'] }}; opacity:0;">
        @yield('preheader')&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $theme['canvas'] }}; background-image:radial-gradient(circle at 92% 6%, {{ $theme['glowA'] }} 0, {{ $theme['glowAClear'] }} 340px), radial-gradient(circle at 4% 96%, {{ $theme['glowB'] }} 0, {{ $theme['glowBClear'] }} 300px);">
        <tr>
            <td align="center" class="tw-gutter" style="padding:40px 24px 48px;">

                <table role="presentation" class="tw-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                    {{-- Wordmark --}}
                    <tr>
                        <td align="center" style="padding:0 0 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td valign="middle" style="padding-right:10px;">
                                        <div style="width:34px; height:34px; border-radius:11px; background-color:{{ $theme['brand'] }}; text-align:center; line-height:34px; font-family:'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif; font-size:18px; font-weight:700; color:{{ $theme['white'] }};">t</div>
                                    </td>
                                    <td valign="middle" style="font-family:'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif; font-size:22px; font-weight:700; letter-spacing:-0.3px; color:{{ $theme['text'] }};">
                                        Tewtora
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td class="tw-card tw-card-pad" style="background-color:{{ $theme['white'] }}; border-radius:28px; padding:12px; box-shadow:0 20px 60px {{ $theme['cardShadow'] }};">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:32px 24px 0; font-family:'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif; font-size:12px; line-height:20px; color:{{ $theme['muted'] }};">
                            @yield('footer_note')
                            <div style="padding-top:12px;">
                                &copy; {{ now()->year }} Tewtora &middot; Managed tutoring with verified teachers
                            </div>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
