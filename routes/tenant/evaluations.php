<?php

use App\Livewire\Tenant\Evaluation\EvaluationCreate;
use App\Livewire\Tenant\Evaluation\EvaluationDetail;
use App\Livewire\Tenant\Evaluation\EvaluationIndex;
use App\Livewire\Tenant\Evaluation\RecommendationIndex;
use Illuminate\Support\Facades\Route;

/*
| Evaluation + the recommendations follow-up register (plan §4). Required
| inside routes/tenant.php's authenticated group, so auth, active,
| tenant.member and 2fa.require already apply.
|
| `/evaluations/create` is declared BEFORE `/evaluations/{evaluation}` —
| otherwise "create" binds as an evaluation ULID and the form 404s. Same rule
| that the reports wizard already depends on.
|
| The recommendations register is a sibling route rather than a tab: an
| unimplemented recommendation is the thing the manual's knowledge-management
| loop exists to chase, and it has to be reachable without first knowing which
| evaluation raised it.
*/
Route::get('/evaluations', EvaluationIndex::class)->name('evaluations.index');
Route::get('/evaluations/create', EvaluationCreate::class)->name('evaluations.create');
Route::get('/evaluations/{evaluation}', EvaluationDetail::class)->name('evaluations.show');

Route::get('/recommendations', RecommendationIndex::class)->name('recommendations.index');
