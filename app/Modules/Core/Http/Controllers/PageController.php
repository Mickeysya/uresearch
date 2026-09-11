<?php

namespace App\Modules\Core\Http\Controllers;

/**
 * Sidebar destinations that exist as navigation today but have no feature
 * behind them yet. Each renders the same "nothing here yet" shell rather
 * than a 404, so the sidebar is honest about what it links to while the
 * real page is built.
 */
class PageController extends Controller
{
    public function notifications()
    {
        return view('core::pages.placeholder', [
            'title' => 'Notifications',
            'description' => 'A single feed of every alert the portal sends you — decisions on your '
                .'applications, attendance early warnings, and reminders — will appear here.',
        ]);
    }

    public function documents()
    {
        return view('core::pages.placeholder', [
            'title' => 'Documents',
            'description' => 'Every file you have uploaded or been issued, in one place, will appear here. '
                .'For now, documents attached to a specific application can be found on that application\'s tracking page.',
        ]);
    }

    public function calendar()
    {
        return view('core::pages.placeholder', [
            'title' => 'Calendar',
            'description' => 'Upcoming deadlines — RPD milestones, appeal windows, reminders — will appear here.',
        ]);
    }

    public function help()
    {
        return view('core::pages.placeholder', [
            'title' => 'Help and Support',
            'description' => 'Guides and a way to reach CGS support directly will appear here.',
        ]);
    }

    public function profile()
    {
        return view('core::pages.placeholder', [
            'title' => 'Profile',
            'description' => 'Editing your details and changing your password will be available here.',
        ]);
    }
}
