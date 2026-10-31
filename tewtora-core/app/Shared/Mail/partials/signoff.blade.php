{{-- @include('shared-mail::partials.signoff', ['line' => …]) --}}
@php($font = "'Poppins', 'Segoe UI', Helvetica, Arial, sans-serif")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:36px; border-top:1px solid #ECEEF5;">
    <tr>
        <td style="padding-top:24px; font-family:{!! $font !!}; font-size:14px; line-height:22px; color:#4B5163;">
            {{ $line }}<br>
            <span style="font-weight:600; color:#111827;">The Tewtora team</span>
        </td>
    </tr>
</table>
