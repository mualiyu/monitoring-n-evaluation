{{--
    Personal notification preferences, oversight shell
    (App\Livewire\Oversight\Settings\NotificationPreferences).
--}}
<div>
    <x-ui.page-header
        :title="__('Notification preferences')"
        :description="__('Choose what reaches you, and how. These settings are yours: they apply on the oversight surface and in any workspace you belong to.')"
        :back="route('oversight.notifications.index')"
        :back-label="__('Back to notifications')"
    />

    @include('livewire.shared.partials.notification-preferences-form')
</div>
