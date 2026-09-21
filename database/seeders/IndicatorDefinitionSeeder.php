<?php

namespace Database\Seeders;

use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\IndicatorDefinition;
use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * The state indicator library — GLOBAL reference data that ships in
 * PRODUCTION, like the sector taxonomy and the statutory reporting calendar.
 * It is the manual's "predetermined indicator list" (digest §4): the thing an
 * MDA's annual return is consolidated against.
 *
 * Deliberately generic and free of any client state's naming, and deliberately
 * SHORT. A library of four hundred measures is a library nobody picks from,
 * and the manual's Q4 indicator retreat exists precisely to keep the list at a
 * size a secretariat can actually review. States extend it from the oversight
 * screen; this is the starting set.
 *
 * Idempotent on `code`, so re-running never duplicates an entry an MDA has
 * already instantiated.
 */
class IndicatorDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, int> $sectors */
        $sectors = Sector::query()->pluck('id', 'code')->all();

        foreach ($this->definitions() as $definition) {
            $sectorCode = $definition['sector'];
            unset($definition['sector']);

            IndicatorDefinition::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    ...$definition,
                    'sector_id' => $sectorCode === null ? null : ($sectors[$sectorCode] ?? null),
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Three tiers, four units and every measurement frequency the deadline
     * engine understands — the set exists partly so every branch of
     * IndicatorAchievement has something real behind it.
     *
     * @return list<array{code: string, name: string, definition: string, focus: string, sector: string|null, unit: IndicatorUnit, default_measurement_frequency: MeasurementFrequency, default_target_type: TargetType, default_tier: IndicatorTier, data_source: string, means_of_verification: string, responsible_collector_text: string, smart_statement: string}>
     */
    private function definitions(): array
    {
        return [
            [
                'code' => 'WKS-RD-01',
                'name' => 'Kilometres of road rehabilitated and handed over',
                'definition' => 'Carriageway length certified complete and formally handed to the maintenance authority. Partial works are excluded until handover.',
                'focus' => 'Transport infrastructure delivery',
                'sector' => 'WKS',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Quarterly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'Handover certificates and site inspection reports',
                'means_of_verification' => 'Signed handover certificate with geotagged photographic evidence.',
                'responsible_collector_text' => 'Director of M&E',
                'smart_statement' => 'Specific to certified carriageway, measurable from handover records, time-bound to the plan period.',
            ],
            [
                'code' => 'WKS-RD-02',
                'name' => 'Average travel time on rehabilitated corridors',
                'definition' => 'Mean journey time in minutes over the rehabilitated corridor, measured on a fixed route at a fixed time of day.',
                'focus' => 'Transport service outcome',
                'sector' => 'WKS',
                'unit' => IndicatorUnit::Time,
                'default_measurement_frequency' => MeasurementFrequency::Biannual,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Intermediate,
                'data_source' => 'Travel-time survey on a fixed route',
                'means_of_verification' => 'Survey instrument, enumerator log and route timings.',
                'responsible_collector_text' => 'Bureau of Statistics',
                'smart_statement' => 'A lower figure is better; the fixed route and time of day make successive rounds comparable.',
            ],
            [
                'code' => 'EDU-CLS-01',
                'name' => 'Classrooms completed and in use',
                'definition' => 'Classrooms certified complete, furnished, and in use for scheduled teaching at the time of inspection.',
                'focus' => 'Learning environment',
                'sector' => 'EDU',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Quarterly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'School inspection returns',
                'means_of_verification' => 'Inspection report countersigned by the head teacher.',
                'responsible_collector_text' => 'M&E Department',
                'smart_statement' => 'Counts only classrooms in actual use, which is what a completed building is for.',
            ],
            [
                'code' => 'EDU-ENR-01',
                'name' => 'Net enrolment rate in served communities',
                'definition' => 'Pupils of official school age enrolled, as a percentage of the official-age population of the served communities.',
                'focus' => 'Access to education',
                'sector' => 'EDU',
                'unit' => IndicatorUnit::Percentage,
                'default_measurement_frequency' => MeasurementFrequency::Annual,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Pdo,
                'data_source' => 'Annual school census and statistics bureau population estimates',
                'means_of_verification' => 'Published school census tables.',
                'responsible_collector_text' => 'Bureau of Statistics',
                'smart_statement' => 'Comparable year on year because both numerator and denominator come from published series.',
            ],
            [
                'code' => 'HLT-FAC-01',
                'name' => 'Health facilities meeting the minimum equipment standard',
                'definition' => 'Facilities scoring full compliance against the state minimum equipment checklist at the time of inspection.',
                'focus' => 'Service readiness',
                'sector' => 'HLT',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Quarterly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'Facility readiness assessment',
                'means_of_verification' => 'Completed checklist signed by the facility officer in charge.',
                'responsible_collector_text' => 'M&E Department',
                'smart_statement' => 'Full compliance only: a partially equipped facility is not a ready one.',
            ],
            [
                'code' => 'HLT-MRT-01',
                'name' => 'Under-five mortality rate',
                'definition' => 'Deaths of children under five per 1,000 live births in the served communities.',
                'focus' => 'Population health impact',
                'sector' => 'HLT',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Annual,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Pdo,
                'data_source' => 'Demographic and health survey; civil registration',
                'means_of_verification' => 'Published survey report.',
                'responsible_collector_text' => 'Bureau of Statistics',
                'smart_statement' => 'A REDUCTION indicator: the target sits below the baseline, and achievement is the share of the intended reduction actually delivered.',
            ],
            [
                'code' => 'WAT-CON-01',
                'name' => 'Households with a functioning water connection',
                'definition' => 'Households with a connection delivering water on the day of inspection. A connected but dry household does not count.',
                'focus' => 'Water access',
                'sector' => 'WAT',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Quarterly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Intermediate,
                'data_source' => 'Utility connection records verified by spot check',
                'means_of_verification' => 'Spot-check report against the connection register.',
                'responsible_collector_text' => 'M&E Focal Officer',
                'smart_statement' => 'Functioning, not merely installed — which is the difference the manual’s spot checks exist to catch.',
            ],
            [
                'code' => 'GOV-PLN-01',
                'name' => 'Annual work plan approved and published',
                'definition' => 'The entity’s costed annual work plan approved by the accounting officer and published. A one-off milestone: it happened or it did not.',
                'focus' => 'Planning discipline',
                'sector' => null,
                'unit' => IndicatorUnit::OneOff,
                'default_measurement_frequency' => MeasurementFrequency::Annual,
                'default_target_type' => TargetType::TimeBound,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'Entity planning records',
                'means_of_verification' => 'Approved plan bearing the approval date and signature.',
                'responsible_collector_text' => 'Director of Planning',
                'smart_statement' => 'A milestone, not a quantity: reporting a percentage of an approval is not information.',
            ],
            [
                'code' => 'GOV-RPT-01',
                'name' => 'Statutory returns filed on time',
                'definition' => 'Progress returns filed on or before their statutory deadline, as a percentage of the returns owed in the period.',
                'focus' => 'Reporting compliance',
                'sector' => null,
                'unit' => IndicatorUnit::Percentage,
                'default_measurement_frequency' => MeasurementFrequency::Quarterly,
                'default_target_type' => TargetType::PercentageAchievement,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'Platform reporting calendar and obligation register',
                'means_of_verification' => 'Compliance board extract for the period.',
                'responsible_collector_text' => 'M&E Secretariat',
                'smart_statement' => 'Already expressed as a share of an agreed total, so achievement is read against the target share directly.',
            ],
            [
                'code' => 'GOV-BEN-01',
                'name' => 'Beneficiaries reached by the programme',
                'definition' => 'Distinct individuals recorded as receiving a programme service in the period. Repeat contacts are counted once.',
                'focus' => 'Programme reach',
                'sector' => 'SOC',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Monthly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Output,
                'data_source' => 'Beneficiary register',
                'means_of_verification' => 'De-duplicated register extract.',
                'responsible_collector_text' => 'Programme Manager',
                'smart_statement' => 'Distinct individuals, so reach cannot be inflated by repeat visits.',
            ],
            [
                'code' => 'AGR-YLD-01',
                'name' => 'Average yield among supported farmers',
                'definition' => 'Mean harvested yield in tonnes per hectare among farmers enrolled in the programme.',
                'focus' => 'Agricultural productivity',
                'sector' => 'AGR',
                'unit' => IndicatorUnit::Number,
                'default_measurement_frequency' => MeasurementFrequency::Annual,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Intermediate,
                'data_source' => 'Crop-cutting survey among enrolled farmers',
                'means_of_verification' => 'Survey dataset and enumerator field sheets.',
                'responsible_collector_text' => 'Bureau of Statistics',
                'smart_statement' => 'Measured by crop cutting rather than farmer recall, which is what makes successive rounds comparable.',
            ],
            [
                'code' => 'ENV-WST-01',
                'name' => 'Share of collected waste disposed at engineered sites',
                'definition' => 'Tonnage delivered to engineered disposal sites as a percentage of total collected tonnage.',
                'focus' => 'Environmental management',
                'sector' => 'ENV',
                'unit' => IndicatorUnit::Percentage,
                'default_measurement_frequency' => MeasurementFrequency::Monthly,
                'default_target_type' => TargetType::Continuous,
                'default_tier' => IndicatorTier::Intermediate,
                'data_source' => 'Weighbridge records at disposal sites',
                'means_of_verification' => 'Monthly weighbridge summary.',
                'responsible_collector_text' => 'M&E Focal Officer',
                'smart_statement' => 'Both figures come from the same weighbridge series, so the share is internally consistent.',
            ],
        ];
    }
}
