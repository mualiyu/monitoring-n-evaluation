{{--
    Published from the framework (Illuminate/Mail/resources/views/text/message.blade.php):
    the plain-text part names the instance, not config('app.name'). See the
    html/message.blade.php beside this directory for why.
--}}
<x-mail::layout>
    <x-slot:header>
        <x-mail::header :url="\App\Support\SurfaceUrl::base()">
            {{ config('platform.instance.name') }}
        </x-mail::header>
    </x-slot:header>

    {{ $slot }}

    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ config('platform.instance.name') }}. @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
