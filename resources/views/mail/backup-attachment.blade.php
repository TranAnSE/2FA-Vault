@component('mail::message')
# 2FA-Vault Encrypted Backup

Please find your encrypted backup file **{{ $filename }}** attached to this email.

The file is double-encrypted (server-side and end-to-end). Store it in a safe location.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
