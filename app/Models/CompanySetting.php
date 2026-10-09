<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class CompanySetting extends Model
{
    /**
     * The one shape cutoff_time is stored in.
     *
     * The column is a `time`, so PostgreSQL returns 'H:i:s' whatever was
     * written. SQLite stores the string verbatim, which is how the form's
     * '09:30' and the seeder's '11:00:00' ended up living side by side in the
     * same column locally and not in production. Normalising on write makes the
     * two drivers agree.
     */
    public const CUTOFF_TIME_FORMAT = 'H:i:s';

    /**
     * Used where no settings row exists yet. The migration's column default is
     * 10:30:00; this is the value the application presents in its absence, and
     * every call site that used to carry its own literal now reads it here.
     */
    public const DEFAULT_CUTOFF_TIME = '11:00:00';

    protected $fillable = [
        'company_id',
        'cutoff_time',
        'timezone',
        'wfh_auto_skip',
        'attendance_absence_enabled',
        'meal_days',
        'primary_admin_id',
        'backup_admin_id',
        'updated_by',
        'settings_changed_at',
    ];

    protected $casts = [
        'settings_changed_at' => 'datetime',
        'wfh_auto_skip' => 'boolean',
        'attendance_absence_enabled' => 'boolean',
        'meal_days' => 'array',
    ];

    /**
     * Normalise on write, so 'H:i' from a time input and 'H:i:s' from a seeder
     * are the same stored value.
     *
     * Anything else throws rather than being stored: a cutoff of '12pm' parses
     * through explode(':') as hour 0, which is a silent midnight cutoff - the
     * count would lock before anyone could change it, and nothing would say
     * why. Request validation rejects it first; this catches the seeder, a
     * console one-liner and a future import.
     */
    public function setCutoffTimeAttribute(?string $value): void
    {
        $this->attributes['cutoff_time'] = $value === null
            ? null
            : static::normalizeCutoffTime($value);
    }

    /**
     * 'H:i' or 'H:i:s' in, canonical 'H:i:s' out.
     *
     * @throws InvalidArgumentException when the value is neither shape
     */
    public static function normalizeCutoffTime(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Cutoff time must be HH:MM or HH:MM:SS, got '{$value}'.");
        }

        [$hour, $minute, $second] = [(int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0)];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new InvalidArgumentException("Cutoff time '{$value}' is not a real time of day.");
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /**
     * The cutoff as hour and minute, for building a moment in the company's
     * timezone.
     *
     * Five call sites were each doing their own explode(':') with their own
     * fallback literal, which is how two different defaults ('11:00' and
     * '10:30:00') came to exist for the same missing value.
     *
     * @return array{0: int, 1: int}
     */
    public function cutoffHourMinute(): array
    {
        return static::cutoffHourMinuteFor($this);
    }

    /**
     * The same, for the several call sites that hold a company whose settings
     * row may not exist. They each used to supply their own fallback.
     *
     * @return array{0: int, 1: int}
     */
    public static function cutoffHourMinuteFor(?self $setting): array
    {
        $parts = explode(':', $setting?->cutoff_time ?: static::DEFAULT_CUTOFF_TIME);

        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }

    /**
     * The cutoff as a person reads it: 'HH:MM', with the seconds nobody sets
     * dropped.
     */
    public function cutoffLabel(): string
    {
        return static::cutoffLabelFor($this);
    }

    public static function cutoffLabelFor(?self $setting): string
    {
        [$hour, $minute] = static::cutoffHourMinuteFor($setting);

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public function getMealDaysAttribute($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) && count($decoded) > 0 ? $decoded : [1, 2, 3, 4, 5];
        }

        return is_array($value) && count($value) > 0 ? $value : [1, 2, 3, 4, 5];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function primaryAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_admin_id');
    }

    public function backupAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_admin_id');
    }

    /**
     * Who last changed these settings. Null for a row nothing has touched since
     * the column was added, or whose user has since been deleted.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
