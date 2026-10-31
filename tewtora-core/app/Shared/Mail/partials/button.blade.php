{{--
    The one primary action in an email, plus the copy-paste fallback link.
    @include('shared-mail::partials.button', ['url' => …, 'label' => …])
    Bulletproof: VML roundrect for Outlook desktop, a styled link elsewhere.
--}}
@php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:36px;">
    <tr>
        <td align="center">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:56px;v-text-anchor:middle;width:504px;" arcsize="22%" stroke="f" fillcolor="#5B6CFF">
                <w:anchorlock/>
                <center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}" target="_blank" style="display:block; background-color:#5B6CFF; border-radius:12px; padding:17px 24px; font-family:{!! $font !!}; font-size:16px; line-height:22px; font-weight:600; color:#FFFFFF; text-align:center; text-decoration:none; box-shadow:0 8px 20px rgba(91, 108, 255, 0.28);">
                {{ $label }} &rarr;
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>

<p style="margin:16px 0 0; font-family:{!! $font !!}; font-size:12px; line-height:19px; color:#8A8FA3; text-align:center;">
    Button not working? Paste this into your browser:<br>
    <a href="{{ $url }}" target="_blank" style="color:#5B6CFF; text-decoration:none; word-break:break-all;">{{ $url }}</a>
</p>
