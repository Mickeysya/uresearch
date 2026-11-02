<?php

return [

    // Public holidays every "working day" in the portal skips (Y-m-d), on
    // top of Saturdays and Sundays -- read by Core\Support\WorkingDayCalendar:
    // Track My Applications' waiting times, and every module's charter or
    // due-date maths that uses it. Empty for now: add UTP / Perak state
    // holidays here (e.g. '2026-12-25') once CGS confirms the list.
    'holidays' => [],

];
