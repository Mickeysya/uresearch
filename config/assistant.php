<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | 'gemini' (default) or 'fake'. FakeModel is deterministic and is what
    | every automated test uses -- see tests/Support/MakesFakeAssistant.php.
    | Nothing here is ever logged or echoed; GEMINI_API_KEY only ever flows
    | from env() into the HTTP header GeminiModel sends, never anywhere else.
    */
    'provider' => env('ASSISTANT_PROVIDER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('ASSISTANT_GEMINI_MODEL'),
        'timeout' => 15,
        'retries' => 1,
    ],

    /**
     * Round 3 PART A.2: tried once, only after the PRIMARY model has
     * failed (503/500/504/timeout/429) and the overall deadline still
     * allows it. Round 4 PART A.2: changed to "gemini-3.8-flash" -- the
     * user's own real AI Studio numbers, below, are for this exact
     * model, so the fallback is now a real, measured choice rather than
     * the "different family, presumably fine" guess Round 3 made.
     */
    'fallback_model' => env('ASSISTANT_GEMINI_FALLBACK_MODEL', 'gemini-3.8-flash'),

    /**
     * Round 3 PART A.1: the whole reliability sequence for one typed
     * question (primary's attempts, its backoff waits, then one
     * fallback attempt) must fit inside this, leaving a margin before
     * the chat widget's own 25-second client timeout. Enforced as an
     * actual deadline (now + this many seconds), not just a per-request
     * timeout, so the total is bounded even if individual calls are
     * slow rather than erroring quickly.
     */
    'total_deadline_seconds' => (int) env('ASSISTANT_TOTAL_DEADLINE_SECONDS', 22),

    /** Backoff between the primary model's own retry attempts (1st and 2nd retry) -- never used after a 429, which is retried on the fallback instead, never the same model. */
    'retry_waits_seconds' => [2, 5],

    /**
     * Round 3 PART A.3: three consecutive PRIMARY failures (any model
     * error, counted across requests, not just within one) opens the
     * circuit for this many seconds -- new requests go straight to the
     * fallback, skipping the primary (and its retries) entirely, until
     * it closes again. A single primary success at any point resets the
     * failure count to zero.
     */
    'circuit_breaker' => [
        'consecutive_failures_to_open' => 3,
        'open_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Free-tier call budget (app-side, enforced BEFORE calling the provider)
    |--------------------------------------------------------------------------
    |
    | Round 4 PART A.3: the user's own real AI Studio numbers per model
    | (requests/minute, requests/day, input tokens/minute), with our own
    | app-side limit held at about 80% of each as a safety margin before
    | Google's own limit would ever actually trip. Round 3 kept one flat
    | pair of numbers applied to both models identically -- real numbers
    | differ hugely per model (gemini-3.5-flash-lite's day limit is
    | 400 here against gemini-3.8-flash's 15), so this is now a map keyed
    | by model name, with 'default' for any model not listed (keeps the
    | old conservative numbers, so an unexpected model name never
    | silently gets an unlimited budget).
    |
    | tokens_per_minute is an ESTIMATE only -- PART A.3 says to estimate
    | a request's input tokens as its character count / 4, and to treat
    | the call as budget-exhausted (same as the request-count check) if
    | the estimate would not fit the remaining minute budget. This is
    | deliberately crude (no real tokenizer call), since the point is
    | staying under Google's real ceiling with margin, not billing
    | accuracy.
    */
    'rate_limits' => [
        'gemini-3.5-flash-lite' => [
            'requests_per_minute' => (int) env('ASSISTANT_PRIMARY_REQUESTS_PER_MINUTE', 12),
            'requests_per_day' => (int) env('ASSISTANT_PRIMARY_REQUESTS_PER_DAY', 400),
            'tokens_per_minute' => (int) env('ASSISTANT_PRIMARY_TOKENS_PER_MINUTE', 200000),
        ],
        'gemini-3.8-flash' => [
            'requests_per_minute' => (int) env('ASSISTANT_FALLBACK_REQUESTS_PER_MINUTE', 4),
            'requests_per_day' => (int) env('ASSISTANT_FALLBACK_REQUESTS_PER_DAY', 15),
            'tokens_per_minute' => (int) env('ASSISTANT_FALLBACK_TOKENS_PER_MINUTE', 200000),
        ],
        /** Any model name not listed above (should not happen in practice) -- the old conservative Round 3 numbers, never an unlimited budget. */
        'default' => [
            'requests_per_minute' => 5,
            'requests_per_day' => 100,
            'tokens_per_minute' => 200000,
        ],
        'user_requests_per_minute' => (int) env('ASSISTANT_USER_REQUESTS_PER_MINUTE', 10),
    ],

    /**
     * Round 4 PART A.4: Google resets a model's daily quota at midnight
     * in this timezone, not server-local time -- only the DAY cache key
     * is built in it; the per-minute key stays in server-local time,
     * since a one-minute window is far too short for a timezone offset
     * to matter at all. Changing this changes which cache key a future
     * call lands on, never an existing counter's own value.
     */
    'day_timezone' => env('ASSISTANT_DAY_TIMEZONE', 'America/Los_Angeles'),

    /** How many of the student's own earlier questions (never answers) are kept and may be sent to the model. */
    'recent_turns' => 5,

    'max_question_length' => 300,

    /*
    |--------------------------------------------------------------------------
    | Keyword rules
    |--------------------------------------------------------------------------
    |
    | Tried before the model, in this order, per role. The first lookup/topic
    | whose phrase list contains a substring of the (lowercased) question
    | wins outright -- no model call at all. See Assistant\KeywordRules.
    | Phrases are deliberately plain substrings, not regex: every one of
    | these is a short, unambiguous phrase a real question would contain.
    */
    'keyword_rules' => [
        // Student lookups. Deliberately NOT a bare "my workstation"/"my
        // seat" -- found live via assistant:evaluate under PART B: that
        // swallowed "How do I RETURN my workstation?" as a substring
        // match before the question ever reached the model, which is
        // exactly the kind of flat-phrase collision PART B's engine is
        // meant to replace. Question-form phrasings only.
        'my_workstation' => ['what is my workstation', 'what is my seat', 'which seat am i', 'where is my seat', 'where is my workstation'],
        // Deliberately NOT bare "my key"/"my locker key" -- found live via
        // STEP 2: that caught "my key got lost, what do I do?" before
        // guidance:key_lost ever got a chance, since nothing in that
        // topic's own phrase list happens to share a substring with this
        // particular wording for the length-ranking in KeywordRules to
        // prefer. Status-asking phrasings only.
        // Found live, Round 3: the bare "key status" entry matched as a
        // substring inside "what do the key STATUSES mean" too (status +
        // "es"), wrongly answering a glossary question with "you have no
        // key on record" -- the same kind of collision as "my workstation"
        // in Round B. Dropped; the remaining phrases all still need "my"
        // or "locker", which a plain glossary question never has.
        'my_locker_key' => ['my locker key status', 'status of my locker key', 'my key status', 'locker key status'],
        'available_seats' => ['available seats', 'free seats', 'empty seat', 'empty seats', 'seats available', 'how many seats'],
        // Round 4 PART B.2: available to BOTH roles -- block N2 vs J2, side by side.
        'block_comparison' => ['n2 and j2', 'n2 vs j2', 'n2 versus j2', 'compare block', 'compare n2', 'compare j2', 'which block is fuller', 'which block is more crowded', 'block is fuller', 'more crowded', 'how crowded is block'],

        // CGS lookups.
        'seat_availability' => ['seat availability', 'how many available', 'free seats in'],
        'overall_stats' => ['overall stats', 'overall statistics', 'total seats', 'dashboard numbers', 'how many seats are available', 'seats are available'],
        'seat_lookup' => ['who is in seat', 'who is sitting in seat', 'seat lookup', 'status of seat'],
        'student_lookup' => ['find student', 'student lookup', 'look up student'],
        'keys_pending_collection' => ['keys pending collection', 'key pending', 'pending collection', 'uncollected key', 'uncollected keys'],
        'keys_pending_return' => ['keys pending return', 'overdue key', 'overdue keys', 'return pending', 'keys overdue', 'overdue for return', 'keys are overdue'],
        'awaiting_key_return_seats' => ['awaiting key return', 'seats awaiting key'],
        'termination_notices' => ['termination notice', 'termination notices', 'seats in termination', 'vacate notice'],
        'maintenance_seats' => ['maintenance seats', 'seats in maintenance', 'under maintenance', 'which seats are in maintenance'],
        'room_occupancy' => ['room occupancy', 'occupancy rate', 'occupancy percentage'],
        'candidacy_expiring' => ['candidacy expiring', 'expiring candidacy', 'expiring soon'],
        'releases_this_week' => ['released this week', 'releases this week', 'seats released'],
        // Round 3 PART E.6.
        'insights_summary' => ['show me the insights', 'show the insights', 'insights page', 'insights summary'],
        // Round 8 PART 5.1 -- natural phrasings for the seven new aggregate lookups.
        'programme_breakdown' => ['which programmes use workstations most', 'programme breakdown', 'programmes use workstations', 'busiest programmes', 'top programmes'],
        'gender_comparison' => ['male versus female occupancy', 'male vs female occupancy', 'male versus female rooms', 'occupancy by gender'],
        'key_status_overview' => ['how many keys are not requested', 'key status overview', 'locker key overview', 'keys by status'],
        'length_of_stay_distribution' => ['how long have students been in their seats', 'length of stay', 'how long have seat holders'],
        'candidacy_expiry_distribution' => ['when do candidacies expire', 'candidacy expiry distribution', 'when does candidacy expire'],
        'seat_status_by_block' => ['seats by status per block', 'seat status by block', 'status per block'],
        'return_outlook' => ['when are keys due back', 'keys due back', 'key return outlook', 'return outlook'],

        // Guidance topics -- student.
        'guidance:select_seat' => ['how do i select', 'how to select a workstation', 'select a seat', 'how to choose a seat'],
        'guidance:visibility_rules' => ['why cant i see', "why can't i see", 'not showing', 'missing seats', 'missing rooms'],
        'guidance:confirmation' => ['confirm my selection', 'confirmation email', 'did not confirm', "didn't confirm", 'do not confirm', 'no email arrived'],
        'guidance:change_or_return' => ['change my workstation', 'return my workstation', 'how to return'],
        'guidance:locker_key_request' => ['how do i request a locker key', 'request a locker key', 'request my key'],
        'guidance:locker_key_collect_return' => ['how do i collect my key', 'collect my key', 'collect my locker key', 'return my key', 'return my locker key', 'collect the key'],
        'guidance:key_lost' => ['lost my key', 'lost my locker key', 'lost the key', 'key is lost'],
        'guidance:termination_vacate' => ['termination notice mean', 'why was my loan terminated', 'when do i have to vacate', 'vacate by'],
        'guidance:eligibility_loan_period' => ['am i eligible', 'eligibility', 'loan period', 'loan duration'],
        'guidance:contact_and_hours' => ['contact cgs', 'opening hours', 'office hours', 'operating hours'],
        'guidance:terms_of_use' => ['term of use', 'terms of use', 'rules of the workstation'],

        // Guidance topics -- CGS.
        'guidance:cgs_reassign' => ['how do i reassign', 'how to reassign'],
        'guidance:cgs_release' => ['how do i release', 'how to release a seat', 'release reasons'],
        'guidance:cgs_assign' => ['how do i assign', 'how to assign a student'],
        'guidance:cgs_maintenance' => ['how do i set maintenance', 'set to maintenance'],
        'guidance:cgs_release_hold' => ['release hold', 'how do i release hold'],
        'guidance:cgs_keys' => ['mark as collected', 'mark as returned', 'mark key'],
        'guidance:cgs_termination' => ['release now', 'withdraw termination', 'how does termination work'],
        'guidance:cgs_search_export' => ['how do i search', 'how do i export', 'export results', 'export history'],
        'guidance:system_rules' => ['what are the workstation rules', 'system rules'],
    ],

    /**
     * Round 4 PART B.1: the SAME wording from a student can mean "what do
     * I do" (the student topic, key) or "what does CGS do about this"
     * (the CGS counterpart, value) depending on who asked -- a question
     * like "what do I do if a student lost their key" is CGS staff
     * asking about a STUDENT's situation, so the student-side keyword
     * match (key_lost) is not wrong, just incomplete for this role. For
     * the CGS role, Pipeline::addCgsTopicCounterparts() adds the
     * counterpart alongside the student topic wherever it appears --
     * topic HINTS before the model call, and the model's own raw
     * "sources" after it responds -- never replacing the student topic,
     * only adding its CGS-side counterpart.
     */
    'cgs_topic_counterparts' => [
        'key_lost' => 'cgs_keys',
        'locker_key_collect_return' => 'cgs_keys',
        'termination_vacate' => 'cgs_termination',
        'select_seat' => 'cgs_reassign',
        'change_or_return' => 'cgs_reassign',
        'confirmation' => 'cgs_system_guide',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic extraction (tried before keyword_rules, before the model)
    |--------------------------------------------------------------------------
    |
    | See Assistant\ParameterExtractor/KeywordRules::extractDeterministic().
    | guidance_lead_ins exists so a HOW-TO question ("How do I release a
    | seat?") is never mistaken for the command it asks about ("Release
    | seat 252") -- an action/manipulation phrase only counts when none of
    | these lead-ins are also present.
    */
    'action_phrases' => [
        'release', 'reassign', 'assign', 'mark as collected', 'mark as returned',
        'mark the key', 'set maintenance', 'terminate', 'withdraw',
    ],

    'manipulation_phrases' => [
        'ignore your rules', 'ignore previous instructions', 'list all students',
        'show all students', 'give me everyone', 'system prompt', 'act as',
    ],

    'guidance_lead_ins' => [
        'how do i', 'how to', 'how can i', 'how does', 'what is the process',
        'what is', 'what are', 'what happens', 'where do i', 'when do i', 'when does',
        "why can't", 'why cant', 'why', 'can i', 'could you explain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lost/damaged key and "mark the key" phrasing (PART 1 #2/#3)
    |--------------------------------------------------------------------------
    |
    | Both are role-routed in KeywordRules::extractDeterministic() rather
    | than a plain phrase list, because the SAME wording needs a DIFFERENT
    | topic depending on who asked: a student gets key_lost/
    | locker_key_collect_return (what to do, as the one who lost it or
    | collects it); CGS gets cgs_keys (how CGS records/handles it) either
    | way. A flat phrase list can only point at one topic name, never two.
    */
    'lost_or_damaged_key_phrases' => [
        'lost my key', 'lost my locker key', 'lost the key', 'key is lost',
        'key got lost', 'my key got lost', 'lose my key', 'missing key',
        'misplaced key', 'damaged key', 'broken key',
        // Round 4 PART D: CGS staff describe a STUDENT'S key, never "my" --
        // "what do i do if a student lost their key" (one of Part D's own
        // required live checks) matched none of the phrases above.
        'lost their key', 'lost his key', 'lost her key',
    ],

    /** "mark [a/the] key as collected/returned" -- the CGS action, or a student asking how it works. */
    'mark_key_status_pattern' => '/\bmark\b.{0,20}\bkey\b.{0,10}\b(collected|returned)\b/',

    /**
     * PART 1 #4: if the model says out_of_scope but one of these words is
     * in the question, it is far more likely an unusual phrasing of an
     * in-scope question than a genuinely unrelated one -- answered as
     * "asked_back" with the suggested questions instead of the flat
     * refusal. Never applied to the DETERMINISTIC out_of_scope result
     * (an explicit manipulation phrase) -- only the model's own guess.
     */
    'domain_words' => [
        'workstation', 'seat', 'room', 'locker', 'key', 'term of use',
        'vacate', 'terminate', 'loan', 'block', 'cgs',
    ],

    /** C2/F.3#2: a room at or above this occupancy percentage is "almost full" -- the Insights page and the almost_full_rooms lookup both read this one value. */
    'almost_full_percent' => (int) env('ASSISTANT_ALMOST_FULL_PERCENT', 90),

    /**
     * PART B.1: the cap on how much knowledge text one model call may be
     * given. All of it fits comfortably under this today (see the
     * report's own size numbers) -- the cap, and GuidanceLibrary::
     * knowledgeTextForRole()'s keyword-scoring fallback, exist so a
     * future knowledge file does not silently blow up the request size
     * or the free-tier token budget.
     */
    'max_knowledge_chars' => (int) env('ASSISTANT_MAX_KNOWLEDGE_CHARS', 35000),

    /**
     * PART B.4: aggregate lookups -- no personal data in their result, so
     * they may get a SECOND model call to turn the structured numbers
     * into two to five sentences of prose. Lookups that return personal
     * data (seat_lookup, student_lookup, my_workstation, my_locker_key)
     * are deliberately never in this list -- those always use the
     * template, never a model call.
     */
    'aggregate_lookups' => [
        'seat_availability', 'overall_stats', 'keys_pending_collection', 'keys_pending_return',
        'awaiting_key_return_seats', 'termination_notices', 'maintenance_seats', 'room_occupancy',
        'almost_full_rooms', 'seat_release_outlook', 'candidacy_expiring', 'releases_this_week',
        'available_seats',
        // Round 4 PART B.2: no personal data in its result either.
        'block_comparison',
        // Round 8 PART 5.1: counts and programme names only -- no names, IDs or emails.
        'programme_breakdown', 'gender_comparison', 'key_status_overview',
        'candidacy_expiry_distribution', 'length_of_stay_distribution',
        'seat_status_by_block', 'return_outlook',
    ],

];
