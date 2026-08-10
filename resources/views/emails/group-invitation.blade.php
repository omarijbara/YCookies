<x-mail::message>
# You have been invited to join {{ $groupName }}

You have been invited to join **{{ $groupName }}** on the YCookies platform.

<x-mail::button :url="$inviteUrl">
Accept Invitation
</x-mail::button>

If you received this email by mistake, simply delete it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
