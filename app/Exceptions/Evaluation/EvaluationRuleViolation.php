<?php

namespace App\Exceptions\Evaluation;

use App\Enums\EvaluationStatus;
use DomainException;

/**
 * A domain precondition refused the write. One class with named constructors,
 * as in the projects and reporting modules: the rules are testable by message,
 * greppable in one place, and every guard states itself in the same vocabulary.
 *
 * These are *domain* failures, not authorization failures — a director holding
 * every permission still cannot approve an evaluation they led. Authorization
 * denials surface as Laravel's AuthorizationException.
 */
class EvaluationRuleViolation extends DomainException
{
    /* ---------------------------------------------------------------- */
    /* The commission */
    /* ---------------------------------------------------------------- */

    public static function subjectRequired(): self
    {
        return new self(
            'An evaluation must name what it is evaluating — either a project or, for a programme-level '
            .'study, the programme it covers. A commission with no subject cannot be reported on.'
        );
    }

    public static function unknownScope(string $scope): self
    {
        return new self("[{$scope}] is not a recognised unit of evaluation.");
    }

    public static function datesOutOfOrder(): self
    {
        return new self('An evaluation cannot end before it starts.');
    }

    /* ---------------------------------------------------------------- */
    /* The team */
    /* ---------------------------------------------------------------- */

    public static function leadRequired(): self
    {
        return new self(
            'An evaluation team needs exactly one lead — the evaluator who signs for the findings and who, '
            .'for that reason, may not approve them.'
        );
    }

    public static function duplicateTeamMember(): self
    {
        return new self('That person is already on this evaluation team.');
    }

    public static function memberNeedsAName(): self
    {
        return new self(
            'A team member is either a platform user or a named external evaluator — an unnamed row on the '
            .'roster tells a reader nothing.'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Scoring and the report */
    /* ---------------------------------------------------------------- */

    public static function unknownCriterion(string $criterion): self
    {
        return new self(
            "[{$criterion}] is not one of this instance's evaluation criteria "
            .'(`evaluation.criteria`). Add it to the setting before scoring against it.'
        );
    }

    public static function scoreOutOfRange(string $score, int $max): self
    {
        return new self("An evaluation score must be between 0 and {$max} — [{$score}] is not.");
    }

    public static function justificationRequired(): self
    {
        return new self(
            'A criterion score needs its reasoning. A bare number with no justification is an opinion, and '
            .'an evaluation is supposed to be the other thing.'
        );
    }

    public static function notEditable(EvaluationStatus $status): self
    {
        return new self(
            "An evaluation that is [{$status->value}] is no longer editable — findings freeze when the report "
            .'goes up for review.'
        );
    }

    public static function unknownSection(string $key): self
    {
        return new self("[{$key}] is not a section of this evaluation's report.");
    }

    /**
     * @param  list<string>  $missing
     */
    public static function reportIncomplete(array $missing): self
    {
        return new self(
            'The report is not finished: '.implode(', ', $missing).'. Every required section has to be written '
            .'before the draft can be marked complete.'
        );
    }

    /**
     * @param  list<string>  $unscored
     */
    public static function scorecardIncomplete(array $unscored): self
    {
        return new self(
            'The scorecard is not finished: '.implode(', ', $unscored).'. Every criterion needs a score and a '
            .'justification before the report goes up for review.'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Separation of duties */
    /* ---------------------------------------------------------------- */

    public static function approverIsLead(): self
    {
        return new self(
            'The evaluation lead cannot approve their own evaluation. Approval is an independent judgement on '
            .'the findings, or it is a signature on your own homework.'
        );
    }

    public static function approverIsSubmitter(): self
    {
        return new self('The person who sent this report up for review cannot also approve it.');
    }

    public static function reasonRequired(): self
    {
        return new self('That step needs a stated reason — it goes on the permanent record of this evaluation.');
    }

    /* ---------------------------------------------------------------- */
    /* Recommendations */
    /* ---------------------------------------------------------------- */

    public static function addresseeRequired(): self
    {
        return new self(
            'A recommendation needs an addressee — a person or a body that owns it. A recommendation addressed '
            .'to nobody is the definition of one that will not be implemented.'
        );
    }

    public static function sourceNotRecommendable(string $type): self
    {
        return new self(
            "Recommendations are raised against a tenant-owned monitoring record; [{$type}] is not one."
        );
    }

    public static function sourceBelongsToAnotherEntity(): self
    {
        return new self('That record belongs to another entity — a recommendation cannot cross workspaces.');
    }

    public static function evidenceRequired(): self
    {
        return new self(
            'Marking a recommendation implemented requires evidence of implementation. "Done" with nothing '
            .'behind it is what makes a follow-up register worthless.'
        );
    }

    public static function supersedingRecommendationRequired(): self
    {
        return new self(
            'Superseding a recommendation means naming the one that replaces it — otherwise it has simply been '
            .'dropped, and the register should say so.'
        );
    }

    public static function cannotSupersedeItself(): self
    {
        return new self('A recommendation cannot supersede itself.');
    }

    public static function addresseeNotInWorkspace(): self
    {
        return new self(
            'A recommendation can only be addressed to someone who belongs to this workspace — '
            .'an evaluation finding is confidential to the entity it is about.'
        );
    }
}
