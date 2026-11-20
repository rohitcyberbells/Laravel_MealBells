<?php

namespace App\Notifications;

/**
 * The stored `type` of every notification that implements NeedsAttention.
 *
 * Read off the classes themselves rather than kept as a list, because a list
 * drifts: the failure would be a new alert quietly never reaching the panel,
 * which is the one direction that matters.
 */
class AttentionTypes
{
    /** @var array<int, class-string>|null */
    protected static ?array $cached = null;

    /**
     * @return array<int, class-string>
     */
    public static function all(): array
    {
        if (static::$cached !== null) {
            return static::$cached;
        }

        $types = [];

        foreach (glob(app_path('Notifications/*.php')) ?: [] as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, NeedsAttention::class)) {
                $types[] = $class;
            }
        }

        sort($types);

        return static::$cached = $types;
    }

    /**
     * Only for tests, which build classes after this has already been read.
     */
    public static function forget(): void
    {
        static::$cached = null;
    }
}
