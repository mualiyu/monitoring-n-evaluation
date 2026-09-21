<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Lifecycle;

use App\Actions\Oversight\ListCertificatesAcrossTenants;
use App\Actions\Oversight\SummariseCertificatesAcrossTenants;
use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The state completion register — every certificate issued by every MDA.
 *
 * READ ONLY, deliberately: certification is an MDA act. The secretariat's
 * interest is oversight of it — what has been signed, by whom, and what is
 * still inside a defects-liability window the state can act within — not the
 * power to sign on an MDA's behalf.
 *
 * Both reads go through app/Actions/Oversight/, which re-check
 * `certificates.view` in the GLOBAL permission team before bypassing tenancy.
 * This component never calls withoutTenancy() itself: the bypass belongs with
 * the authorization check that justifies it, in one place.
 */
#[Layout('layouts::oversight')]
class CertificateRegister extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $revoked = false;

    public function mount(): void
    {
        abort_unless($this->user()->holdsGlobalPermission('certificates.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedRevoked(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'type', 'revoked']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->type !== '' || $this->revoked;
    }

    /**
     * The Action takes models, not ids — it filters with whereBelongsTo() so
     * that no hand-written tenant clause exists anywhere, including here.
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'tenant' => $this->tenantId !== '' ? $this->tenants()->firstWhere('id', (int) $this->tenantId) : null,
            'type' => $this->type !== '' ? CertificateType::tryFrom($this->type) : null,
            'search' => $this->search === '' ? null : $this->search,
            'revoked' => $this->revoked,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Certificate>
     */
    #[Computed]
    public function certificates(): LengthAwarePaginator
    {
        return app(ListCertificatesAcrossTenants::class)($this->user(), $this->filters());
    }

    /**
     * @return array{total: int, practical: int, final: int, under_defects_liability: int, revoked: int}
     */
    #[Computed]
    public function stats(): array
    {
        return app(SummariseCertificatesAcrossTenants::class)($this->user());
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return CertificateType::options();
    }

    /**
     * CSV of the state register under the filters in force. The authorization
     * lives in the Action, which re-checks the global permission before the
     * bypass — the same gate the on-screen list passes through.
     */
    public function export(): StreamedResponse
    {
        // Paginating an export would silently truncate it, so the Action is
        // asked for one large page rather than the screen's 25.
        $certificates = app(ListCertificatesAcrossTenants::class)($this->user(), $this->filters(), 5000);

        $filename = 'state-certificates-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($certificates): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Entity'), __('Certificate'), __('Type'), __('Project'),
                __('Project reference'), __('Issued on'), __('Issued by'),
                __('Defects liability ends'), __('Status'),
            ]);

            /** @var Certificate $certificate */
            foreach ($certificates->items() as $certificate) {
                fputcsv($handle, [
                    $certificate->tenant->name,
                    $certificate->reference,
                    $certificate->type->shortLabel(),
                    $certificate->project->title,
                    $certificate->project->reference,
                    // The state's wall clock, not UTC.
                    InstanceTime::local($certificate->issued_at)->toDateString(),
                    $certificate->issuedBy?->name,
                    $certificate->defects_liability_ends_on?->toDateString(),
                    $certificate->isRevoked() ? __('Withdrawn') : __('In force'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.lifecycle.certificate-register');
    }
}
