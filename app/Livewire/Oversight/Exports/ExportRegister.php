<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Exports;

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Exporting\ReportExporter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The generated-artifact register (plan §4): every file this platform has
 * produced, who asked for it, under which filters, and whether it is still
 * held.
 *
 * WHY A REGISTER AT ALL. An export is data leaving a government platform. A
 * spreadsheet forwarded to a Commissioner six months later means nothing
 * without the question that produced it — "3,218 projects" is not a figure
 * until you know which filters were applied — and an export nobody can trace
 * is data leaving unobserved. So every artifact is recorded before a byte is
 * written, and the row that says who took it is never deleted, even once
 * retention has pruned the file.
 *
 * ReportExport is GLOBAL, carrying `generated_for_tenant_id` as provenance
 * rather than as a scope key, so this screen needs no tenancy bypass. The
 * cross-workspace gate lives in ReportExportPolicy, which is also what stands
 * between one workspace's artifact and another's.
 */
#[Layout('layouts::oversight')]
class ExportRegister extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $dataset = '';

    #[Url(except: '')]
    public string $format = '';

    #[Url(as: 'state', except: '')]
    public string $status = '';

    #[Url(as: 'mine', except: false)]
    public bool $mineOnly = false;

    /** A refusal, shown verbatim — an artifact retention has already pruned. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ReportExport::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDataset(): void
    {
        $this->resetPage();
    }

    public function updatedFormat(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedMineOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'dataset', 'format', 'status', 'mineOnly']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->dataset !== '' || $this->format !== ''
            || $this->status !== '' || $this->mineOnly;
    }

    /**
     * @return LengthAwarePaginator<int, ReportExport>
     */
    #[Computed]
    public function exports(): LengthAwarePaginator
    {
        return ReportExport::query()
            // Eager-loaded because every row names its requester and, where it
            // has one, its workspace and its consolidation — and lazy loading
            // is prevented outside production.
            ->with([
                'generatedBy:id,name',
                'generatedFor:id,name,slug',
                'consolidatedReport:id,ulid,reference,title',
            ])
            ->when(
                $this->search !== '',
                fn (Builder $query) => $query->where('title', 'like', '%'.$this->search.'%'),
            )
            ->when(
                $this->dataset !== '' && ReportDataset::tryFrom($this->dataset) !== null,
                fn (Builder $query) => $query->where('dataset', $this->dataset),
            )
            ->when(
                $this->format !== '' && ExportFormat::tryFrom($this->format) !== null,
                fn (Builder $query) => $query->where('format', $this->format),
            )
            ->when(
                in_array($this->status, [ReportExport::STATUS_PENDING, ReportExport::STATUS_READY, ReportExport::STATUS_FAILED], true),
                fn (Builder $query) => $query->where('status', $this->status),
            )
            ->when(
                $this->mineOnly,
                fn (Builder $query) => $query->where('generated_by_id', $this->user()->id),
            )
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * The register's summary row. One grouped query for the outcomes, plus the
     * count of artifacts still on disk — "how much of what we handed out can
     * still be produced" is the question retention makes worth asking.
     *
     * @return array{total: int, ready: int, pending: int, failed: int, mine: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = ReportExport::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'total' => array_sum($byStatus),
            'ready' => $byStatus[ReportExport::STATUS_READY] ?? 0,
            'pending' => $byStatus[ReportExport::STATUS_PENDING] ?? 0,
            'failed' => $byStatus[ReportExport::STATUS_FAILED] ?? 0,
            'mine' => ReportExport::query()->where('generated_by_id', $this->user()->id)->count(),
        ];
    }

    /**
     * Hand an artifact back.
     *
     * The file is NOT streamed from here. The register redirects to the
     * signed, authenticated, policy-checked download route — the only way a
     * generated artifact leaves this platform, and the place the
     * cross-workspace provenance check is spent.
     */
    public function download(ReportExporter $exporter, string $ulid): void
    {
        $this->failure = null;

        $export = ReportExport::query()->where('ulid', $ulid)->firstOrFail();

        if (! $export->isDownloadable()) {
            // A pruned or failed artifact is an answer, not a 403: the row is
            // deliberately kept after the file has gone, and a screen that
            // said "forbidden" would misdescribe why.
            $this->failure = $export->hasFailed()
                ? __('That artifact failed to generate. Run the export again — the register keeps the failed attempt on the record.')
                : __('That artifact is no longer held. Generated files are pruned on a retention schedule; the record of who took one is not.');

            return;
        }

        $this->authorize('download', $export);

        $this->redirect($exporter->downloadUrl($export));
    }

    /** @return array<string, string> */
    public function datasetOptions(): array
    {
        $options = [];

        foreach (ReportDataset::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function formatOptions(): array
    {
        $options = [];

        foreach (ExportFormat::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return [
            ReportExport::STATUS_READY => __('Ready'),
            ReportExport::STATUS_PENDING => __('Generating'),
            ReportExport::STATUS_FAILED => __('Failed'),
        ];
    }

    /**
     * The badge vocabulary an artifact's outcome renders as. Status is icon +
     * text platform-wide, so a state with no pill of its own borrows the
     * nearest rather than inventing a chip the component cannot colour.
     *
     * @return array{status: string, label: string, icon: string}
     */
    public function outcome(ReportExport $export): array
    {
        if ($export->hasFailed()) {
            return ['status' => 'rejected', 'label' => __('Failed'), 'icon' => 'exclamation-triangle'];
        }

        if ($export->isPending()) {
            return ['status' => 'pending', 'label' => __('Generating'), 'icon' => 'clock'];
        }

        if (! $export->isDownloadable()) {
            return ['status' => 'waived', 'label' => __('No longer held'), 'icon' => 'pause-circle'];
        }

        return ['status' => 'approved', 'label' => __('Ready'), 'icon' => 'check-circle'];
    }

    /* ------------------------------------------------------------------ */

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.exports.export-register');
    }
}
