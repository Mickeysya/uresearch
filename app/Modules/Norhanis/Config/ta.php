<?php

/**
 * The Teaching Assistant plan on the Travel form, for GA/GRA students.
 *
 * Read through Support\TaStatus, never directly.
 */
return [
    // Fixed per semester, never trusted from the form.
    'hours_required' => 84,

    // The months a UTP postgraduate semester starts in (January, May,
    // September). "TA hours completed this semester" counts from the most
    // recent of these, or from the appointment's start if that is later.
    'semester_start_months' => [1, 5, 9],
];
