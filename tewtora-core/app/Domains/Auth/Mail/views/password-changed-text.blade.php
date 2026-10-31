{{-- Plain-text part — raw output ({!! !!}) since nothing here is rendered as HTML. --}}
{!! $greeting !!}

This is a quick confirmation that the password for {!! $email !!} was changed on {!! $changedAtLabel !!}.

We've signed you out everywhere, so sign in again with your new password: {!! $loginUrl !!}

WASN'T YOU? Someone may have access to your email. Reset your password now ({!! $forgotUrl !!}) and reply to this email so our team can help secure your account.

Stay safe,
The Tewtora team

--
You're receiving this security notice for {!! $email !!}. We send it whenever your password changes. It can't be turned off.
