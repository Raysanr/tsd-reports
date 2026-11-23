<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

/** Hub announcements board (2026-11-23): admins post and remove; everyone reads on the Hub. */
class AnnouncementController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'     => ['required', 'string', 'max:120'],
            'body'      => ['nullable', 'string', 'max:2000'],
            'important' => ['nullable', 'boolean'],
            'ends_on'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $a = Announcement::create($data + ['important' => $request->boolean('important'), 'created_by' => $request->user()->id]);
        ActivityLogger::log('announcement.posted', $a, "Posted announcement \"{$a->title}\"");

        return redirect()->route('hub')->with('success', 'Announcement posted.');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        ActivityLogger::log('announcement.removed', null, "Removed announcement \"{$announcement->title}\"");

        return redirect()->route('hub')->with('success', 'Announcement removed.');
    }
}
