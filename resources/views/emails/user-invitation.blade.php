@component('mail::message')
Hello!
<br/><br/>
You have been invited to join **{{ config('app.name') }}**.<br/>
Click the button below to complete your registration and set up your account:<br/><br/>

<x-mail::button :url="$invitationUrl">
Accept invitation
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.<br/><br/>

If you did not expect this invitation, you can safely ignore this email.<br/><br/>

Regards,<br/>
{{ config('app.name') }}
@endcomponent
