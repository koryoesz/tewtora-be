{{-- Plain-text part — raw output ({!! !!}) since nothing here is rendered as HTML. --}}
{!! $greeting !!}

We received a request to reset the password for your Tewtora account. Choose a new password here (the link expires in {{ $expiresInMinutes }} minutes and can only be used once):

{!! $resetUrl !!}

Didn't ask for this? You can safely ignore this email. Your password won't change unless someone opens the link above and chooses a new one.

Happy learning,
The Tewtora team

--
You're receiving this because a password reset was requested for {!! $email !!}. Never share this link. Tewtora will never ask you for your password.
