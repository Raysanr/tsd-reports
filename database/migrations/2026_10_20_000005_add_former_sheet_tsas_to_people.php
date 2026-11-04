<?php

use App\Support\ScoreboardSheetImport;
use Illuminate\Database\Migrations\Migration;

/** Adds the IS Report sheet's former TSAs to the Archiving System's People
 *  (status Resigned) — see ScoreboardSheetImport::syncFormerPeople(). */
return new class extends Migration
{
    public function up(): void
    {
        // Teams must exist first so they land in the right one (teams only —
        // no roster sync here, it would write actor-less Activity Log rows).
        \App\Support\ArchiveRosterSync::ensureTeams();
        ScoreboardSheetImport::syncFormerPeople();
    }

    public function down(): void
    {
        \App\Models\Employee::where('notes', 'like', 'Imported from the IS Report Google Sheet%')->delete();
    }
};
