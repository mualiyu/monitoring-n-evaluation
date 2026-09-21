<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Lifecycle;

use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The workspace's completion register: every certificate this MDA has issued.
 *
 * Read only. Certificates are signed on the project's own certification screen,
 * where the preconditions are visible — a register that could also issue would
 * be a screen where the most consequential act in the platform is two clicks
 * from a search box.
 *
 * One code path for every role: `visibleTo()` narrows Consultant/FieldMonitor
 * to their assignments while MDA staff see the whole register, so isolation is
 * proven once rather than per screen. The TenantScope confines everything to
 * the bound MDA before any of that runs.
 */
#[Layout('layouts::tenant')]
class CertificateRegister extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    /** '' = in force, 'revoked' = withdrawn, 'all' = both. */
    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Certificate::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->type !== '' || $this->status !== '';
    }

    /**
     * @return LengthAwarePaginator<int, Certificate>
     */
    #[Computed]
    public function certificates(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'issuedBy:id,name',
            ])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Builder<Certificate> */
    private function query(): Builder
    {
        return Certificate::query()
            ->visibleTo($this->user())
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->status === '', fn (Builder $q) => $q->active())
            ->when($this->status === 'revoked', fn (Builder $q) => $q->whereNotNull('revoked_at'))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('reference', 'like', $this->term())
                ->orWhereIn('project_id', $this->searchMatches())));
    }

    private function term(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
    }

    /**
     * Projects matching the search box, as a subquery — the TenantScope on the
     * inner query confines it to the bound MDA exactly as the outer query is
     * confined.
     *
     * @return Builder<Project>
     */
    private function searchMatches(): Builder
    {
        $term = $this->term();

        return Project::query()
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $term)
                ->orWhere('reference', 'like', $term))
            ->select('id');
    }

    /**
     * The state of the register, deliberately NOT filtered: a stat row that
     * moves with the search box cannot answer "what have we certified".
     *
     * @return array{total: int, practical: int, final: int, under_defects_liability: int}
     */
    #[Computed]
    public function stats(): array
    {
        $now = Carbon::now();

        $row = Certificate::query()
            ->visibleTo($this->user())
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN revoked_at IS NULL THEN 1 END) as total')
            ->selectRaw(
                'COUNT(CASE WHEN revoked_at IS NULL AND type = ? THEN 1 END) as practical',
                [CertificateType::PracticalCompletion->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN revoked_at IS NULL AND type = ? THEN 1 END) as final_completion',
                [CertificateType::FinalCompletion->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN revoked_at IS NULL AND defects_liability_ends_on >= ? THEN 1 END) as under_defects',
                [$now->toDateString()],
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'practical' => (int) ($row->practical ?? 0),
            'final' => (int) ($row->final_completion ?? 0),
            'under_defects_liability' => (int) ($row->under_defects ?? 0),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return CertificateType::options();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return [
            'revoked' => __('Withdrawn only'),
            'all' => __('In force and withdrawn'),
        ];
    }

    /**
     * CSV of the register under exactly the filters in force. Authorization is
     * repeated HERE and not merely inherited from mount(): this is a
     * network-callable method whose rows go straight past the view layer into
     * a file someone forwards.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $query = $this->query()
            ->with(['project:id,title,reference', 'issuedBy:id,name', 'revokedBy:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        $filename = 'certificates-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Certificate'), __('Type'), __('Project'), __('Project reference'),
                __('Issued on'), __('Issued by'), __('Defects liability ends'),
                __('Status'), __('Withdrawal reason'),
            ]);

            $query->chunk(500, function (iterable $certificates) use ($handle): void {
                /** @var Certificate $certificate */
                foreach ($certificates as $certificate) {
                    fputcsv($handle, [
                        $certificate->reference,
                        $certificate->type->shortLabel(),
                        $certificate->project->title,
                        $certificate->project->reference,
                        // The state's wall clock, not UTC.
                        InstanceTime::local($certificate->issued_at)->toDateString(),
                        $certificate->issuedBy?->name,
                        $certificate->defects_liability_ends_on?->toDateString(),
                        $certificate->isRevoked() ? __('Withdrawn') : __('In force'),
                        $certificate->revocation_reason,
                    ]);
                }
            });

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
        return view('livewire.tenant.lifecycle.certificate-register');
    }
}
