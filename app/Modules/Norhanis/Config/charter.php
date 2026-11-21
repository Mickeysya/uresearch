<?php

/**
 * CGS's client charter: the working days each approval stage promises to
 * take, from the process workflow charts CGS issues for each module.
 *
 * Keyed by module_type, then Stage::key -- the same permanent keys the
 * workflows store in rows -- so a stage relabelled on screen keeps its figure.
 *
 * The charts also give the student 1-3 days to fill in the form. That time is
 * before submission, so it is not here: every figure below counts from the
 * moment the application reaches the stage.
 *
 * Read through StageDurations, never directly, so the fallback rule lives in
 * one place.
 */
return [
    // 3 (student) + 1 + 1 + 1 + 3: the final approval takes 3 working days
    // (Norhanis, 20 Nov 2026, matching the viva slides and dissertation).
    'travel' => [
        'supervisor' => 1,
        'chair' => 1,
        'cgs_review' => 1,
        'dean' => 3,
        // Not on the chart, which predates local travel going past the Chair
        // (agreed with CGS, Oct 2026). 3 days, the same as the Dean's final
        // approval for international travel.
        'senior_director' => 3,
    ],

    // Chart total 12 days = 3 (student) + 2 + 2 + 3 + 2. Payment through the
    // Project Director happens outside UResearch and has no charter figure.
    'claims_student' => [
        'supervisor' => 2,
        'chair' => 2,
        'cgs_review' => 3,
        'manager' => 2,
    ],

    // Chart total 12 days = 3 (student) + 2 + 2 + 3 + 2.
    'publication' => [
        'supervisor' => 2,
        'chair' => 2,
        'cgs_review' => 3,
        'senior_director' => 2,
    ],
];
