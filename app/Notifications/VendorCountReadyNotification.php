<?php

namespace App\Notifications;

use App\Models\MealCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The number the kitchen cooks to.
 *
 * This was in-app only, which meant the one message the vendor genuinely needs
 * arrived nowhere they were looking: they would have to open MealBells every
 * morning to find out how many meals to make. It is now mailed as well as kept
 * in the feed.
 *
 * Carries no employee detail at all - not a name, a code or a skip reason. The
 * vendor is told how many, never who, and a test asserts it.
 */
class VendorCountReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $tiffinServiceId  When given, the mail also carries the
     *                                     vendor's other companies for the same
     *                                     date - they cook for all of them at
     *                                     once, so one company's number on its
     *                                     own is not the kitchen's workload.
     */
    public function __construct(
        public string $companyName,
        public string $date,
        public int $finalCount,
        public ?int $tiffinServiceId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        // A vendor login created without an address of its own carries a
        // stand-in one that is never written to, so mailing it would bounce or
        // vanish. The in-app copy still arrives either way.
        return $notifiable->canReceiveMail()
            ? ['database', 'mail']
            : ['database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $app = config('app.name', 'MealBells');

        $mail = (new MailMessage)
            ->subject("[{$app}] {$this->companyName}: {$this->finalCount} meals for {$this->date}")
            ->greeting('Final count confirmed')
            ->line("**{$this->companyName}** &mdash; **{$this->finalCount} meals** on {$this->date}.")
            ->line('This count is locked. Any change after this point is sent to you as a separate update.');

        $breakdown = $this->breakdown();

        if (count($breakdown) > 1) {
            $mail->line('')
                ->line("**Everything for {$this->date}:**");

            foreach ($breakdown as $row) {
                $mail->line("- {$row['company']}: **{$row['count']}** ".($row['locked'] ? '(locked)' : '(not locked yet)'));
            }

            $mail->line('**Total: '.array_sum(array_column($breakdown, 'count')).' meals.**');
        }

        return $mail
            ->action('Open the preparation view', url('/tiffin-admin/preparation?date='.$this->date))
            ->salutation('— '.$app);
    }

    /**
     * Every company this vendor serves on this date.
     *
     * Rows not yet locked are included and labelled: leaving them out would
     * understate the morning's work, and showing them unlabelled would imply a
     * number that can still move is final.
     *
     * @return array<int, array{company: string, count: int, locked: bool}>
     */
    protected function breakdown(): array
    {
        if ($this->tiffinServiceId === null) {
            return [];
        }

        return MealCount::with('company:id,name')
            ->where('tiffin_service_id', $this->tiffinServiceId)
            ->where('date', $this->date)
            ->get()
            ->map(fn (MealCount $count) => [
                'company' => $count->company?->name ?? 'Unnamed company',
                'count' => (int) $count->adjusted_total,
                'locked' => $count->locked_at !== null,
            ])
            ->sortBy('company')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'vendor_count_ready',
            'title' => 'Daily Meal Count Ready',
            'message' => "Final meal count for {$this->companyName} on {$this->date} is confirmed at {$this->finalCount} meals.",
            'company_name' => $this->companyName,
            'date' => $this->date,
            'final_count' => $this->finalCount,
        ];
    }
}
