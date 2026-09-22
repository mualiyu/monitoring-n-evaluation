{{--
    Published from the framework (Illuminate/Mail/resources/views/html/header.blade.php)
    to remove its special case: when the slot read "Laravel" it hot-linked the
    Laravel logo from laravel.com into every message — third-party branding
    and a remote image load in a government inbox. The lockup is text only.

    $slot is the escaped instance name passed in by message.blade.php.
--}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
