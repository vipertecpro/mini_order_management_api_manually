<?php

namespace App\Jobs;

use App\Mail\OrderPlaced;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessOrder implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Order $order) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // A retry after a failed mail send shouldn't send a second email.
        if ($this->order->status !== 'pending') {
            return;
        }

        Mail::to($this->order->user)->send(new OrderPlaced($this->order));

        $this->order->update(['status' => 'completed']);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->order->update(['status' => 'failed']);

        Log::error("Order #{$this->order->id} could not be processed.", [
            'exception' => $exception->getMessage(),
        ]);
    }
}
