<?php

namespace App\Notifications\Reservation;

use App\Models\Payment;
use App\Models\ReservationGroup;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroupDepositVerifiedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ReservationGroup $group,
        public readonly Payment $payment
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->group->loadMissing(['reservations.facility', 'user']);

        $lines = $this->group->reservations->map(function ($res) {
            $schedule = $res->check_in && $res->check_out
                ? $res->check_in->format('M d, Y g:i A') . ' to ' . $res->check_out->format('M d, Y g:i A')
                : 'Standard schedule';

            return [
                'name' => $res->facility?->name ?? 'Service',
                'code' => $res->reservation_code,
                'schedule' => $schedule,
                'guests' => $res->guest_count,
            ];
        })->all();

        return (new MailMessage)
            ->subject('We verified your deposit for group booking ' . $this->group->group_code)
            ->markdown('mail.group-deposit-verified', [
                'guestName' => $this->group->guest_name,
                'groupCode' => $this->group->group_code,
                'amountPaid' => Money::of($this->payment->amount),
                'confirmedPolicy' => $this->group->policy_version === config('resort.policy.confirmed_version'),
                'approved' => $this->group->isApproved(),
                'referenceNumber' => $this->payment->reference_number ?: 'See your booking',
                'remainingBalance' => Money::of($this->group->outstandingBalance()),
                'lines' => $lines,
                'url' => route('customer.reservations.index', ['group' => $this->group->group_code]),
                'resortPhone' => config('resort.phone'),
                'resortPhoneHref' => config('resort.phone_href'),
            ]);
    }
}
