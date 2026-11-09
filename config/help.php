<?php

/*
|--------------------------------------------------------------------------
| Help and Support (/help)
|--------------------------------------------------------------------------
|
| Every word on the Help and Support page lives here, so changing the copy
| never means editing Blade. Read by Core\Http\Controllers\HelpController.
|
| GENERAL ONLY. This page explains how the system works: signing in, roles,
| statuses, approvals, notifications, uploads and contact. Questions about
| one module (Travel, Viva, Workstation, Candidacy, Attendance, GA/GRA...)
| belong to that module's own help, not here. Where an answer needs an
| example, name several modules, never just one.
|
| Audiences (HelpController resolves exactly one per viewer):
|   student   $user->isStudent()
|   admin     $user->isAdmin()
|   cgs       $user->isCgs() -- the four CGS roles
|   approver  everyone else: supervisor, chair, dean_pgr, academic_exec,
|             faculty, registry
|
| FAQ sections:
|   id         stable, used in each question's anchor ({id}-{n}, from 1), so
|              /help#apps-4 opens the fourth Applications answer. Reordering
|              questions changes their anchors; add new ones at the end.
|   icon       compass, file-check, award, inbox, layout-dashboard, shield,
|              bell, paperclip or monitor (drawn in help/index.blade.php)
|   audiences  ['all'], or any of 'student', 'approver', 'cgs', 'admin'
|
| Each question:
|   q       the question
|   a       one paragraph, or a list of paragraphs
|   list    optional bullet list of ['term' => ..., 'text' => ...]
|   badges  true renders the list's terms as status badges; each item then
|           needs 'tone': draft, pending, returned, approved or rejected
|   link    optional ['route' => name, 'label' => text], drawn only when
|           Route::has(route), so a link into a module that is not
|           installed simply does not appear
|
*/

return [

    // The CGS contact, shown to students and approvers. Reuses the
    // TRACKING_HELP_* keys from config/tracking.php, so the "Need help?" box
    // on an application and this page never disagree on name, email or
    // hours. NOTE: tracking.php has its own TRACKING_HELP_PHONE (still a
    // placeholder); this page uses HELP_PHONE, the official CGS hotline.
    'contact' => [
        'name' => env('TRACKING_HELP_NAME', 'Centre for Graduate Studies (CGS)'),
        'email' => env('TRACKING_HELP_EMAIL', 'cgs@utp.edu.my'),
        'phone' => env('HELP_PHONE', '1-300-22-8887'),
        'hours' => env('TRACKING_HELP_HOURS', 'Monday to Friday, 8:30am to 5:00pm'),
        'portal_url' => env('HELP_PORTAL_URL', 'https://utp.microsoftcrmportals.com/'),
        'website_url' => env('HELP_CGS_URL', 'https://utpinfo.utp.edu.my/Pages/Academic/Centre-of-Graduate-Studies.aspx'),
    ],

    // The system-support contact, shown to CGS staff and the administrator
    // instead of the CGS contact above (CGS cannot usefully be told to
    // contact CGS). An empty value hides its row; with no email the card
    // says "Support contact not set yet."
    // TODO: team to confirm who supports CGS staff and admins.
    'support' => [
        'name' => env('HELP_SUPPORT_NAME', 'UResearch system administrator'),
        'email' => env('HELP_SUPPORT_EMAIL'),
        'hours' => env('HELP_SUPPORT_HOURS'),
    ],

    // Banner subtitle: "Search {N} common questions, or {phrase}."
    'contact_phrase' => [
        'student' => 'reach the Centre for Graduate Studies directly',
        'approver' => 'reach the Centre for Graduate Studies directly',
        'cgs' => 'contact system support',
        'admin' => 'contact system support',
    ],

    // The "Popular:" buttons under the search box. Each fills the search box
    // with its term.
    'popular' => [
        'student' => [
            ['label' => 'Application returned', 'term' => 'returned'],
            ['label' => 'Expected decision date', 'term' => 'expected'],
            ['label' => 'Upload size', 'term' => 'large'],
            ['label' => 'Withdraw', 'term' => 'withdraw'],
        ],
        'approver' => [
            ['label' => 'Approve several', 'term' => 'approve'],
            ['label' => 'Return vs reject', 'term' => 'return'],
            ['label' => 'Give a reason', 'term' => 'reason'],
            ['label' => 'Other departments', 'term' => 'department'],
        ],
        'cgs' => [
            ['label' => 'Find my queues', 'term' => 'queue'],
            ['label' => 'Overdue applications', 'term' => 'overdue'],
            ['label' => 'Find a student', 'term' => 'student record'],
            ['label' => 'Wrong role', 'term' => 'role'],
        ],
        'admin' => [
            ['label' => 'Add a user', 'term' => 'add a user'],
            ['label' => 'Change a role', 'term' => 'role'],
            ['label' => 'Audit logs', 'term' => 'audit'],
            ['label' => "Why can't I approve", 'term' => 'approve'],
        ],
    ],

    // "Before you get in touch".
    'contact_tips' => [
        'student' => [
            'Quote your application reference number',
            'Say which page you were on',
            'Attach a screenshot if something looks wrong',
        ],
        'staff' => [
            "Quote the application reference or the user's email",
            'Say which page you were on',
            'Attach a screenshot if something looks wrong',
        ],
    ],

    'about' => [
        'heading' => 'One portal for every postgraduate process',
        'system' => [
            "UResearch 2.0 is UTP's postgraduate portal, managed by the Centre for Graduate Studies. It replaces email threads and paper forms with one place to submit, review and track applications.",
            'Students submit and track; supervisors, CGS staff and other approvers decide from their own queue; everyone is notified at each step.',
        ],
        'tags' => [
            'Candidacy and progress',
            'Viva and thesis',
            'GA / GRA and letters',
            'Supervision and attendance',
            'Travel and claims',
            'Workstations',
        ],
        'cgs_heading' => 'Centre for Graduate Studies',
        'cgs_summary' => 'Postgraduate admission, progress, funding, examination and completion.',
    ],

    'leadership' => [
        ['name' => 'Professor Ts Dr Mohd Fadzil B Hassan', 'title' => 'Dean, Postgraduate and Research'],
        ['name' => 'Assoc. Prof. Dr Balbir Singh A/L Mahinder Singh', 'title' => 'Senior Director, Centre for Graduate Studies'],
        ['name' => 'Mr Ho Guan Yih', 'title' => 'Manager'],
    ],

    // "Who handles what". The topic mapping is to be confirmed with CGS.
    'units' => [
        [
            'name' => 'Student Support & Programme Administration',
            'topics' => 'Supervision, programme matters, general enquiries',
            'staff' => [
                ['name' => 'Mohamed Aqil Zafry B Zainoddin', 'title' => 'Senior Executive', 'email' => 'aqil.zainoddin@utp.edu.my'],
                ['name' => 'Amirul Hariz B Yunus', 'title' => 'Senior Assistant', 'email' => 'amirul.yunus@utp.edu.my'],
            ],
        ],
        [
            'name' => 'Student Funding & Training Development',
            'topics' => 'GA / GRA, extensions, letters, allowances',
            'staff' => [
                ['name' => 'Nurul Akmar Bt Zainuddin', 'title' => 'Senior Executive', 'email' => 'akmar.zainuddin@utp.edu.my'],
                ['name' => 'Zulkifly B M Daud', 'title' => 'Senior Assistant', 'email' => 'zulkiflymo@utp.edu.my'],
            ],
        ],
        [
            'name' => 'Examination & Research Assessment',
            'topics' => 'Viva, examiners, thesis submission, stage gates',
            'staff' => [
                ['name' => 'Waheda Rahayu Bt Rashid', 'title' => 'Senior Executive', 'email' => 'wahedarahayu_rashid@utp.edu.my'],
                ['name' => 'Mohammad Syahmi Ifwat bin Mohd Jafri', 'title' => 'Senior Assistant', 'email' => 'syahmi.jafri@utp.edu.my'],
            ],
        ],
        [
            'name' => 'Student Monitoring & Completion',
            'topics' => 'Attendance, candidacy, completion',
            'staff' => [
                ['name' => 'Nurul Asmira Bt Sulaiman', 'title' => 'Senior Executive', 'email' => null],
                ['name' => 'Norshahira Bt Norzam', 'title' => 'Senior Assistant', 'email' => 'norshahira.norzam@utp.edu.my'],
            ],
        ],
    ],

    'sections' => [

        [
            'id' => 'start',
            'title' => 'Getting started',
            'icon' => 'compass',
            'audiences' => ['all'],
            'questions' => [
                ['q' => 'What is UResearch 2.0?', 'a' => "UTP's postgraduate portal, managed by the Centre for Graduate Studies (CGS). It brings research progress, viva, thesis submission, claims and approvals into one place, so they no longer run through email and paper forms."],
                ['q' => 'Who can use UResearch 2.0?', 'a' => 'Postgraduate students, supervisors, Academic Executives, CGS staff and other approving staff, such as the Dean of Postgraduate and Research.'],
                ['q' => 'How do I sign in?', 'a' => 'Use your UTP email address and your UResearch password on the sign-in page.'],
                ['q' => "I can't sign in. What should I do?", 'a' => 'Check your full UTP email and that Caps Lock is off. After five failed attempts sign-in pauses for about a minute, so wait and try again. If it still fails, use the contact on this page.'],
                ['q' => 'How do I change my password or contact details?', 'a' => 'Open your profile from the card at the bottom of the sidebar. Contact details and password are updated separately there.', 'link' => ['route' => 'profile.show', 'label' => 'Go to my profile']],
                ['q' => 'Why do I see different menus from my colleagues?', 'a' => 'The sidebar only shows the pages for your role. A student, an approver, CGS staff and the administrator each see different menus.'],
                ['q' => 'I think my role is wrong or a page is missing.', 'a' => 'Use the contact on this page with your name, UTP email and the role you expected, and your account will be checked.'],
            ],
        ],

        [
            'id' => 'apps',
            'title' => 'Applications and status',
            'icon' => 'file-check',
            'audiences' => ['student'],
            'questions' => [
                ['q' => 'How do I submit an application?', 'a' => 'Choose the application from My Application in the sidebar, fill in the form and click Submit. Each form shows which documents it needs before you start.'],
                ['q' => 'Can I save an application and finish it later?', 'a' => 'Yes, on forms that have a Save draft button. Your draft waits until you come back, and nobody else can see it until you submit it. Drafts of forms such as GA Extension and GA/GRA Certification are listed under My Drafts in the sidebar.', 'link' => ['route' => 'nureen.drafts', 'label' => 'Open My Drafts']],
                ['q' => 'How do I check the status of my application?', 'a' => 'Go to Track My Applications. Each application shows its current stage and who it is waiting on.', 'link' => ['route' => 'applications.index', 'label' => 'Open Track My Applications']],
                [
                    'q' => 'What do the application statuses mean?',
                    'a' => 'Every application has one of these statuses:',
                    'badges' => true,
                    'list' => [
                        ['term' => 'Draft', 'tone' => 'draft', 'text' => 'Saved, not submitted yet.'],
                        ['term' => 'Pending', 'tone' => 'pending', 'text' => 'Waiting for the reviewer at its current stage.'],
                        ['term' => 'Returned', 'tone' => 'returned', 'text' => 'Sent back to you to fix. Resubmit and it goes back to the same reviewer, not the start.'],
                        ['term' => 'Approved', 'tone' => 'approved', 'text' => 'Every approval stage is complete.'],
                        ['term' => 'Rejected', 'tone' => 'rejected', 'text' => 'A final decision. Read the reason; you may need to submit a new application.'],
                    ],
                ],
                ['q' => 'My application was returned. What do I do?', 'a' => 'Read the reason in your notification or email, open the application from Track My Applications, make the changes and resubmit. It goes back to the reviewer who returned it.'],
                ['q' => 'Can I edit an application after submitting it?', 'a' => 'Not while it is pending. Contact the staff member currently handling it. Where the process allows it, they can return it to you so you can make changes; otherwise you may need to submit a new application.'],
                ['q' => 'Can I withdraw or cancel an application?', 'a' => 'Not from the portal yet. Contact CGS and quote the application reference, and they will close it for you.'],
                ['q' => 'What is the Expected Decision Date?', 'a' => "Some applications, such as GA Extension, Supervision and GA/GRA Certification requests, show an estimate of when the decision is due. It counts the Client Charter's working days for each stage still ahead. It is a guide, not a guarantee, and moves if a stage takes longer.", 'link' => ['route' => 'nureen.expected-dates', 'label' => 'See my expected decision dates']],
                ['q' => 'Why is my application taking a long time?', 'a' => 'Each application passes through one or more approval stages. Track My Applications shows which stage it is at, so you know who to follow up with.'],
                ['q' => 'My supervisor or approver is away. What happens?', 'a' => 'Your application waits at that stage until they act. Some processes send a reminder when a request stays with one person for too long. If it is urgent, contact CGS.'],
            ],
        ],

        [
            'id' => 'letters',
            'title' => 'Letters and signatures',
            'icon' => 'award',
            'audiences' => ['student'],
            'questions' => [
                ['q' => 'Where do I get a letter that was issued to me?', 'a' => 'When an application that produces a letter is approved (for example an appointment or certification letter), the letter is generated as a PDF. Download it from that application in Track My Applications, or from Documents.', 'link' => ['route' => 'documents.index', 'label' => 'Open documents']],
                ['q' => 'What is My Signatures for?', 'a' => 'Some forms and letters are stamped with your signature. Upload it once under My Signatures and it is used wherever it is needed, instead of signing each form by hand.', 'link' => ['route' => 'signatures.index', 'label' => 'Open My Signatures']],
            ],
        ],

        [
            'id' => 'approve',
            'title' => 'Approving applications',
            'icon' => 'inbox',
            'audiences' => ['approver'],
            'questions' => [
                ['q' => 'Where do I find tasks that need my action?', 'a' => 'Under Pending My Action in the sidebar. Each queue lists what is waiting for you, longest waiting first by default.'],
                ['q' => 'Can I approve several applications at once?', 'a' => "On most queues, yes. Tick the rows, then choose Approve selected or Reject selected. Queues where each decision needs a reason or its own checks don't offer this; open and decide each one individually there."],
                ['q' => 'What is the difference between Return and Reject?', 'a' => 'Reject is final and ends the application. Return, where your queue offers it, sends the application back for changes instead of ending it, either to the student or, on some processes, to an earlier reviewer. Once it is fixed, it comes back to the same stage.'],
                ['q' => 'How do I give the student a reason?', 'a' => 'Write it in the Remarks box on the decision form before you decide. Remarks are shown to the student and recorded in the audit trail. Some processes require a reason when you reject or return.'],
                ['q' => "What happens if I don't act on an application?", 'a' => 'It stays in your queue and is marked overdue once it has waited too long. Some processes send you a reminder after a few days, and CGS can see what is overdue.'],
                ['q' => "Why can't I see applications from other departments?", 'a' => 'Chairs of Department and Academic Executives only see applications from their own department.'],
                ['q' => 'Where is my signature used?', 'a' => "Some letters and forms are stamped with the approver's signature. Keep yours up to date under My Signatures.", 'link' => ['route' => 'signatures.index', 'label' => 'Open My Signatures']],
            ],
        ],

        [
            'id' => 'cgs',
            'title' => 'The CGS desk',
            'icon' => 'layout-dashboard',
            'audiences' => ['cgs'],
            'questions' => [
                ['q' => 'Where are my queues?', 'a' => 'Under Applications in the sidebar, grouped by application type. Each queue lists what is waiting at a CGS stage, longest waiting first by default.'],
                ['q' => 'Can I decide several applications at once?', 'a' => "On most queues, yes. Tick the rows, then choose Approve selected or Reject selected. Queues where each decision needs a reason or its own checks don't offer this; open and decide each one individually there."],
                ['q' => 'What is the difference between Return and Reject?', 'a' => 'Reject is final and ends the application. Return, where your queue offers it, sends the application back for changes instead of ending it, either to the student or, on some processes, to an earlier reviewer. Once it is fixed, it comes back to the same stage.'],
                ['q' => "How do I find a student's record?", 'a' => "A full student directory is planned under Students in the sidebar but is not built yet. For now, search a queue by application number, student name or matric number."],
                ['q' => 'How do I see what is overdue or stuck?', 'a' => 'Each queue shows how long every item has waited and marks it once it is overdue.'],
                ['q' => 'A student says a page is missing or their role is wrong.', 'a' => 'Ask for their UTP email and the page they expected, then contact system support (on this page) to have the account checked. Roles are managed by the administrator.'],
            ],
        ],

        [
            'id' => 'admin',
            'title' => 'Administration',
            'icon' => 'shield',
            'audiences' => ['admin'],
            'questions' => [
                ['q' => "How do I add a user or change someone's role?", 'a' => "Open Users and Roles under Administration. Add a staff account with its role, department and a starting password, or edit an account to change its role. Student accounts are not created here. A new role changes the sidebar and queues that person sees the next time they load a page.", 'link' => ['route' => 'admin.users.index', 'label' => 'Open Users and Roles']],
                ['q' => 'How do I manage departments?', 'a' => 'Open Departments under Administration. Departments decide which applications department-scoped roles, such as Chairs of Department and Academic Executives, can see.', 'link' => ['route' => 'admin.departments.index', 'label' => 'Open Departments']],
                ['q' => "Why can't I approve or reject anything?", 'a' => 'The administrator owns no approval stage by design, so no queue offers you Approve or Reject. Every decision belongs to the role at that stage.'],
                ['q' => 'What do the Audit Logs show?', 'a' => 'A record of who did what and when, such as sign-ins and password changes, submissions and decisions on applications, and documents being uploaded or opened. Use it to answer "who did this?". Changes made in Users and Roles are not recorded there yet.', 'link' => ['route' => 'admin.audit.index', 'label' => 'Open Audit Logs']],
                ['q' => 'What is in System Settings?', 'a' => 'Nothing can be changed there yet. System-wide values, such as thresholds and reminder timings, are still set in code, and changing one affects every user, so agree it with CGS and the development team first.'],
                ['q' => "Can I reset a user's password?", 'a' => 'Not from the portal yet. Ask the user to sign in and change it from their profile, or contact system support.'],
            ],
        ],

        [
            'id' => 'notif',
            'title' => 'Notifications and calendar',
            'icon' => 'bell',
            'audiences' => ['all'],
            'questions' => [
                ['q' => 'How will I know when something changes?', 'a' => 'You get an in-app notification (the red number on Notification in the sidebar) and an email to your UTP address.', 'link' => ['route' => 'notifications.index', 'label' => 'Open notifications']],
                ['q' => "I didn't receive an email.", 'a' => 'Check your spam or junk folder. Every update is also on the Notification page, so nothing is lost if an email goes astray.'],
                ['q' => 'Will I be reminded about deadlines?', 'a' => 'Yes, for processes that have them. Reminders arrive as notifications and emails.'],
                ['q' => 'What does the Calendar show?', 'a' => 'Important dates for you, such as viva sessions and deadlines, taken from the applications you are part of.', 'link' => ['route' => 'calendar.index', 'label' => 'Open calendar']],
            ],
        ],

        [
            'id' => 'docs',
            'title' => 'Documents and uploads',
            'icon' => 'paperclip',
            'audiences' => ['all'],
            'questions' => [
                ['q' => 'What file types can I upload?', 'a' => 'Most uploads accept PDF, Word, Excel and image files (JPG or PNG); a few accept PDF only. Single-file uploads take up to 10 MB, and fields that take several files allow up to 5 MB each. If a file is refused, the message says why.'],
                ['q' => 'My file is too large. What should I do?', 'a' => 'Compress the PDF, for example with Adobe Acrobat\'s "Reduce File Size" or a similar tool, then upload it again.'],
                ['q' => 'Are uploaded documents secure?', 'a' => 'Yes. Files are stored privately and can only be opened by the student and the staff involved in that application.'],
                ['q' => 'Where can I find all the files I can see?', 'a' => 'The Documents page lists every file you are allowed to open, across all applications.', 'link' => ['route' => 'documents.index', 'label' => 'Open documents']],
            ],
        ],

        [
            'id' => 'tech',
            'title' => 'Technical help',
            'icon' => 'monitor',
            'audiences' => ['all'],
            'questions' => [
                ['q' => 'Which browser should I use?', 'a' => 'The latest version of Chrome, Edge, Firefox or Safari.'],
                ['q' => "A page isn't updating.", 'a' => 'Refresh the page. New tasks and notifications appear when the page reloads.'],
                ['q' => "I found a bug or something isn't working.", 'a' => 'Use the contact on this page with a screenshot, the page you were on, and a short description of what you did and what happened.'],
            ],
        ],

    ],

];
