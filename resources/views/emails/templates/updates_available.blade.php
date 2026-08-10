<x-mail::message>
# Component Updates Available

There are {{ count($updatesFound) }} component updates available for your YCookies template library:

<x-mail::table>
| Template Name       | Update Version |
| :------------------ |:-------------- |
@foreach($updatesFound as $update)
| {{ $update['template'] }} | {{ $update['new_version'] }} |
@endforeach
</x-mail::table>

You can perform these updates in batch via the admin panel.

<x-mail::button :url="config('app.url') . '/admin/1/services'">
Go to Services
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
