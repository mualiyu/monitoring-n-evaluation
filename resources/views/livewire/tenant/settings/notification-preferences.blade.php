{{--
    Personal notification preferences
    (App\Livewire\Tenant\Settings\NotificationPreferences).
--}}
<div>
    <x-ui.page-header
        :title="__('Notification preferences')"
        :description="__('Choose what reaches you, and how. These settings are yours and follow you into every workspace you belong to.')"
        :back="route('tenant.settings.index')"
        :back-label="__('Back to workspace settings')"
    />

    @include('livewire.shared.partials.notification-preferences-form')
</div>
