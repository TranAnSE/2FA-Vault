@component('mail::message')
# 2FA-Vault Auto-Backup

Your scheduled backup **{{ $filename }}** has been processed.

@if ($succeeded)
All destinations received the backup successfully.
@else
**Some destinations failed:**
@foreach ($failed as $label)
- {{ $label }}
@endforeach

Please review your backup destination settings.
@endif

**Generated:** {{ $generatedAt }} (UTC)

Thanks,<br>
{{ config('app.name') }}
@endcomponent
