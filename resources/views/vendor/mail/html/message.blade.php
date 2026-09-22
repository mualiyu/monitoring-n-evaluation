{{--
    Published from the framework (Illuminate/Mail/resources/views/html/message.blade.php).
    Header lockup and footer name the INSTANCE (config/platform.php), never
    config('app.name'): white-label rule, and APP_NAME is the framework default
    on most machines, which footed every message with a framework copyright.
    The header links to the instance's public portal, built from the platform
    domain the same way every queued link is (SurfaceUrl), not from APP_URL.

    The unescaped $slot / $subcopy are the framework's own rendered Markdown
    component output, not user input.
--}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="\App\Support\SurfaceUrl::base()">
{{ config('platform.instance.name') }}
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('platform.instance.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
