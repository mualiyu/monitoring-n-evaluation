<?php

namespace App\Actions\Evaluation;

use App\Support\SettingsRepository;

/**
 * The evaluation report template: the manual's eleven sections (digest §4),
 * resolvable per instance and per MDA.
 *
 * WHY THIS IS AN ACTION AND NOT A CONSTANT. The eleven sections below are the
 * Ondo/World-Bank pattern, which is the *generic* Nigerian state format — not
 * a universal one. A state that wants a "gender and inclusion" chapter, or
 * that folds acknowledgements into the introduction, must be able to say so
 * without a release. Sections are therefore ROWS seeded from this template
 * (see the evaluation_report_sections migration), and the template itself is a
 * setting: `evaluation.report_sections`, read through SettingsRepository's
 * tenant-override → instance-setting → code-default chain.
 *
 * There is no `platform.evaluation.report_sections` config key yet, so the
 * default below is the last link in that chain. Adding the key to
 * config/platform.php would move the default to where the other domain
 * numbers live and change nothing else — flagged to the integrator.
 *
 * A malformed override falls back to the default whole rather than seeding a
 * half-built report, exactly as SettingsRepository::ints() does.
 */
class ResolveReportTemplate
{
    /**
     * The eleven sections of the manual's evaluation report format.
     *
     * `required` marks the sections that must be WRITTEN before a draft can be
     * called complete. The title page, the contents and the appendices are
     * not among them: the first two are generated from the record and the last
     * is a place to put instruments, not prose.
     *
     * @var list<array{key: string, heading: string, required: bool}>
     */
    public const DEFAULT_SECTIONS = [
        ['key' => 'title_page', 'heading' => 'Title page', 'required' => false],
        ['key' => 'table_of_contents', 'heading' => 'Table of contents', 'required' => false],
        ['key' => 'acknowledgements', 'heading' => 'Acknowledgements', 'required' => false],
        // "2–3 standalone pages" in the manual: the section a commissioner
        // reads when they read nothing else.
        ['key' => 'executive_summary', 'heading' => 'Executive summary', 'required' => true],
        ['key' => 'introduction', 'heading' => 'Introduction and background', 'required' => true],
        ['key' => 'objectives_and_scope', 'heading' => 'Objectives, scope and evaluation questions', 'required' => true],
        ['key' => 'methodology', 'heading' => 'Methodology and limitations', 'required' => true],
        ['key' => 'findings', 'heading' => 'Findings', 'required' => true],
        // The manual asks for these "per user type, prioritized, costed,
        // timetabled" — which is exactly the recommendations register, so this
        // section carries the narrative and the register carries the tracking.
        ['key' => 'recommendations', 'heading' => 'Recommendations', 'required' => true],
        ['key' => 'lessons_learned', 'heading' => 'Lessons learned', 'required' => true],
        ['key' => 'appendices', 'heading' => 'Appendices', 'required' => false],
    ];

    /**
     * @return list<array{key: string, heading: string, required: bool}>
     */
    public function __invoke(): array
    {
        $configured = app(SettingsRepository::class)->get(
            'evaluation',
            'report_sections',
            self::DEFAULT_SECTIONS,
        );

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_SECTIONS;
        }

        $sections = [];
        $seen = [];

        foreach ($configured as $section) {
            if (! is_array($section)) {
                return self::DEFAULT_SECTIONS;
            }

            $key = $section['key'] ?? null;
            $heading = $section['heading'] ?? null;

            // A duplicate key would give one evaluation two rows claiming to
            // be the same section, and the completeness guard would then have
            // to pick one.
            if (! is_string($key) || $key === '' || ! is_string($heading) || $heading === '' || isset($seen[$key])) {
                return self::DEFAULT_SECTIONS;
            }

            $seen[$key] = true;

            $sections[] = [
                'key' => $key,
                'heading' => $heading,
                'required' => (bool) ($section['required'] ?? true),
            ];
        }

        return $sections;
    }
}
