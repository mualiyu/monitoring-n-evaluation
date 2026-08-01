{{--
    <x-ui.badge /> — status pill. Status is ALWAYS icon + text, never colour alone
    (colour-blind users, greyscale printing of board packs, sunlight on a cheap panel).

    Props
      status   Report lifecycle: draft | submitted | under_review | approved | rejected
               Delivery health:  overdue | on_track | behind
               Project lifecycle (App\Enums\ProjectStatus): draft | awarded | mobilized
                 | in_progress | completed | certified | closed | suspended | cancelled
               Generic: on_hold

               Pass the enum straight through — <x-ui.badge :status="$project->status->value" />
      label    override the default wording (terminology is configurable per instance)
      size     sm | md
      icon     override the default icon

    Unknown statuses degrade to a neutral pill with the humanised key, so a new
    enum value never renders as a blank chip.

        <x-ui.badge status="overdue" />
        <x-ui.badge status="under_review" label="With M&E officer" />
--}}
@props([
    'status' => 'draft',
    'label' => null,
    'size' => 'md',
    'icon' => null,
])

@php
    $map = [
        'draft' => ['icon' => 'pencil-square', 'label' => __('Draft'), 'tone' => 'neutral'],
        'submitted' => ['icon' => 'paper-airplane', 'label' => __('Submitted'), 'tone' => 'info'],
        'under_review' => ['icon' => 'eye', 'label' => __('Under review'), 'tone' => 'info'],
        'approved' => ['icon' => 'check-circle', 'label' => __('Approved'), 'tone' => 'positive'],
        'rejected' => ['icon' => 'x-circle', 'label' => __('Rejected'), 'tone' => 'critical'],
        'overdue' => ['icon' => 'exclamation-triangle', 'label' => __('Overdue'), 'tone' => 'critical'],
        'on_track' => ['icon' => 'arrow-trending-up', 'label' => __('On track'), 'tone' => 'positive'],
        'behind' => ['icon' => 'arrow-trending-down', 'label' => __('Behind schedule'), 'tone' => 'warning'],
        'completed' => ['icon' => 'flag', 'label' => __('Completed'), 'tone' => 'brand'],
        'certified' => ['icon' => 'shield-check', 'label' => __('Certified'), 'tone' => 'brand'],
        'on_hold' => ['icon' => 'pause-circle', 'label' => __('On hold'), 'tone' => 'warning'],
        'cancelled' => ['icon' => 'x-mark', 'label' => __('Cancelled'), 'tone' => 'neutral'],

        // Project lifecycle (§2 state machine). Tone follows the arc: nothing
        // committed (neutral) → committed (info) → work happening (brand) →
        // finished (positive/brand) → stopped (warning).
        'awarded' => ['icon' => 'clipboard-check', 'label' => __('Awarded'), 'tone' => 'info'],
        'mobilized' => ['icon' => 'map-pin', 'label' => __('Mobilized'), 'tone' => 'info'],
        'in_progress' => ['icon' => 'arrow-path', 'label' => __('In progress'), 'tone' => 'brand'],
        'closed' => ['icon' => 'check-circle', 'label' => __('Closed'), 'tone' => 'neutral'],
        'suspended' => ['icon' => 'pause-circle', 'label' => __('Suspended'), 'tone' => 'warning'],
    ];

    $config = $map[$status] ?? [
        'icon' => 'information-circle',
        'label' => Str::headline((string) $status),
        'tone' => 'neutral',
    ];

    $tones = [
        'neutral' => 'bg-neutral-soft text-neutral-ink ring-line',
        'info' => 'bg-info-soft text-info-ink ring-info/30',
        'positive' => 'bg-positive-soft text-positive-ink ring-positive/30',
        'warning' => 'bg-warning-soft text-warning-ink ring-warning/40',
        'critical' => 'bg-critical-soft text-critical-ink ring-critical/30',
        'brand' => 'bg-brand-soft text-brand-ink ring-brand/30',
    ];

    $sizes = [
        'sm' => 'gap-1 px-1.5 py-0.5 text-xs',
        'md' => 'gap-1.5 px-2 py-1 text-xs sm:text-sm',
    ];
@endphp

<span
    {{ $attributes->class([
        'inline-flex max-w-full items-center rounded-md font-medium ring-1 ring-inset',
        $tones[$config['tone']],
        $sizes[$size] ?? $sizes['md'],
    ]) }}
>
    <x-ui.icon :name="$icon ?? $config['icon']" class="size-3.5 shrink-0" />
    <span class="truncate">{{ $label ?? $config['label'] }}</span>
</span>
