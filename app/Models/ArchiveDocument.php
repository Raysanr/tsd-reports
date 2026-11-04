<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** TSD Archiving System — metadata for one uploaded file on the 'archive' disk. */
class ArchiveDocument extends Model
{
    use SoftDeletes;

    public const DISK = 'archive';

    public const CATEGORIES = [
        'contract'   => 'Contracts',
        'sop'        => 'SOPs & Policies',
        'memo'       => 'Memos',
        'evaluation' => 'Evaluations',
        'report'     => 'Reports',
        'id'         => 'IDs & Personal Records',
        'other'      => 'Other',
    ];

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'zip'];

    public const MAX_KB = 20480; // 20 MB

    protected $fillable = [
        'title', 'category', 'process_folder', 'process_subfolder_id', 'incident_id', 'description', 'tags', 'department_id', 'employee_id', 'uploaded_by',
        'path', 'original_name', 'mime_type', 'size', 'expires_at',
    ];

    protected $casts = ['expires_at' => 'date'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /** Previewable inline in the browser (PDF/images); everything else downloads. */
    public function isPreviewable(): bool
    {
        return in_array($this->extension(), ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    public function humanSize(): string
    {
        $bytes = (float) $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? (int) $bytes : number_format($bytes, 1)) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '';
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }

    /** Extension → [label, Tailwind tile classes] for the file-type icon tile. */
    public function typeMeta(): array
    {
        return match (true) {
            $this->extension() === 'pdf'                                  => ['PDF', 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300'],
            $this->isImage()                                              => ['IMG', 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300'],
            in_array($this->extension(), ['doc', 'docx', 'txt'], true)   => ['DOC', 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300'],
            in_array($this->extension(), ['xls', 'xlsx', 'csv'], true)   => ['XLS', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300'],
            in_array($this->extension(), ['ppt', 'pptx'], true)          => ['PPT', 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300'],
            default                                                       => [strtoupper($this->extension() ?: 'FILE'), 'bg-slate-100 text-slate-600 dark:bg-slate-500/10 dark:text-slate-300'],
        };
    }

    /** The Documented Process subfolder this file sits in (null = folder top level). */
    public function processSubfolder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProcessSubfolder::class, 'process_subfolder_id');
    }
}
