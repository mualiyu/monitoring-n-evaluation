<?php

use App\Livewire\Oversight\Evaluation\EvaluationBoard;
use App\Livewire\Oversight\Evaluation\RecommendationBoard;
use Illuminate\Support\Facades\Route;

/*
| Cross-MDA evaluation + the state-wide follow-up register. Required inside
| routes/oversight.php's authenticated group, so auth, active, the oversight
| role gate and 2fa.require already apply.
|
| Both screens are READ-ONLY here by design: the secretariat commissions and
| reads evaluations, and chases recommendations, but editing an MDA's findings
| from the oversight surface would make them the secretariat's opinion rather
| than the evaluator's.
*/
Route::get('/evaluations', EvaluationBoard::class)->name('evaluations.index');
Route::get('/recommendations', RecommendationBoard::class)->name('recommendations.index');
