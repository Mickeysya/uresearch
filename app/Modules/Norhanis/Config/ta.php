<?php

/**
 * TA hours: the Travel form's TA plan for GA/GRA students, and the Daily Log
 * TA Hours sheet each student fills in.
 *
 * Read through Support\TaStatus or TaHourLog, never directly.
 */
return [
    // Fixed per semester, never trusted from the form.
    'hours_required' => 84,

    // The most TA hours that count in one calendar month. No carry-forward.
    'monthly_cap' => 21,

    // The months a UTP postgraduate semester starts in (January, May,
    // September). "TA hours completed this semester" counts from the most
    // recent of these, or from the appointment's start if that is later.
    'semester_start_months' => [1, 5, 9],

    // The Activity drop-down on the Daily Log, as in UCampus. The key is
    // stored in ta_hour_logs.activity_type, so it is permanent; the label is
    // display-only and safe to reword.
    'activities' => [
        'tutorial' => 'Tutorial',
        'focused_group_tutorial' => 'Focused Group Tutorial',
        'special_training' => 'Conduct Special Training',
        'lab_demonstration' => 'Lab Demonstration',
        'short_assessment' => 'Evaluating short assignment / assessment',
        'invigilation' => 'Invigilation of Final Exam / Test',
        'project_based' => 'Project-based courses',
        'large_class_lab' => 'Courses with large no of students (approx. 80 students) with lab component/field work',
        'sim_development' => 'Preparation on SIM Development for Master Programme by Coursework Mode (ODL)',
        'workbook_content' => 'Preparation of Module Workbook: Develop Course Content',
        'workbook_arrangement' => 'Preparation of Module Workbook: Arrangement Course content Based on Chapters according to SIM templates',
        'content_sim_materials' => 'Preparation of Content: Creation SIM materials',
        'content_sim_organisation' => 'Preparation of Content: Organization of course content based on UTP SIM Chapters Template',
        'bapc_assessment' => 'Assessment for Bi-Annual Postgraduate Conference (BAPC)',
    ],
];
