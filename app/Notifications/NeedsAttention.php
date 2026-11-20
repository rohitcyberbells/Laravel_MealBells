<?php

namespace App\Notifications;

/**
 * A notification someone has to do something about.
 *
 * The dashboard's alerts panel carries only these. Everything else we send is
 * routine - the day's count, the number the kitchen cooks to, a menu going
 * out - and a panel of routine notices is one people stop reading, which is
 * exactly when a real one arrives and looks no different.
 *
 * Marked on the notification rather than listed in the controller, so adding
 * a new alert is one `implements` away and cannot be forgotten somewhere else.
 */
interface NeedsAttention {}
