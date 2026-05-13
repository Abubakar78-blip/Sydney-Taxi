<?php
// ============================================================
// app/Services/StripeService.php
// ============================================================
namespace App\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\Refund;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-06-20');
    }

    /**
     * Create or retrieve Stripe customer
     */
    public function ensureCustomer(\App\Models\User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = Customer::create([
            'name'  => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);
        return $customer->id;
    }

    /**
     * Create a PaymentIntent
     */
    public function createPaymentIntent(
        int $amount,       // in cents
        string $currency = 'aud',
        ?string $customerId = null,
        array $metadata = [],
        ?string $paymentMethodId = null,
        bool $confirm = false,
    ): array {
        $params = [
            'amount'                    => $amount,
            'currency'                  => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata'                  => $metadata,
        ];

        if ($customerId) $params['customer'] = $customerId;
        if ($paymentMethodId) {
            $params['payment_method'] = $paymentMethodId;
            $params['confirm']        = true;
            $params['return_url']     = url('/booking/return');
        }

        $intent = PaymentIntent::create($params);

        return [
            'payment_intent_id' => $intent->id,
            'client_secret'     => $intent->client_secret,
            'status'            => $intent->status,
            'amount'            => $amount,
        ];
    }

    /**
     * Confirm payment intent
     */
    public function confirmPaymentIntent(string $paymentIntentId): array
    {
        $intent = PaymentIntent::retrieve($paymentIntentId);
        return [
            'status'    => $intent->status,
            'succeeded' => $intent->status === 'succeeded',
            'amount'    => $intent->amount,
        ];
    }

    /**
     * Refund a payment
     */
    public function refund(string $chargeId, ?int $amountCents = null): array
    {
        $params = ['charge' => $chargeId];
        if ($amountCents) $params['amount'] = $amountCents;

        $refund = Refund::create($params);

        return [
            'refund_id' => $refund->id,
            'status'    => $refund->status,
            'amount'    => $refund->amount,
        ];
    }

    /**
     * Validate Stripe webhook signature
     */
    public function validateWebhook(string $payload, string $signature): object
    {
        try {
            return Webhook::constructEvent(
                $payload,
                $signature,
                config('services.stripe.webhook_secret')
            );
        } catch (SignatureVerificationException $e) {
            throw new \Exception('Invalid webhook signature');
        }
    }

    /**
     * Create Payment Request button (Apple/Google Pay)
     */
    public function createPaymentRequestIntent(int $amount, string $label): array
    {
        $intent = PaymentIntent::create([
            'amount'      => $amount,
            'currency'    => 'aud',
            'description' => $label,
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return ['client_secret' => $intent->client_secret];
    }
}

// ============================================================
// app/Services/SmsService.php  
// ============================================================
class SmsService
{
    private $twilio;
    private string $from;

    public function __construct()
    {
        $this->from = config('services.twilio.from');
    }

    private function client(): \Twilio\Rest\Client
    {
        if (!$this->twilio) {
            $this->twilio = new \Twilio\Rest\Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );
        }
        return $this->twilio;
    }

    /**
     * Send an SMS message
     */
    public function send(string $to, string $message): bool
    {
        if (empty($to) || empty(config('services.twilio.sid'))) {
            \Log::info("SMS (mock): To: {$to}\nMessage: {$message}");
            return true;
        }

        try {
            $this->client()->messages->create($to, [
                'from' => $this->from,
                'body' => $message,
            ]);
            return true;
        } catch (\Exception $e) {
            \Log::error("SMS send failed: {$e->getMessage()}");
            return false;
        }
    }

    public function sendBookingConfirmation(\App\Models\Booking $booking): bool
    {
        $msg = "✅ Sydney Taxi: Booking {$booking->booking_number} confirmed!\n"
             . "📍 {$booking->pickup_address}\n"
             . "🏁 {$booking->dropoff_address}\n"
             . "💰 Est. $" . number_format($booking->estimated_fare, 2) . "\n"
             . "Track: sydneytaxi.com.au/track/{$booking->booking_number}";
        return $this->send($booking->customer->phone, $msg);
    }

    public function sendDriverAssigned(\App\Models\Booking $booking): bool
    {
        $driver = $booking->driver;
        $vehicle = $booking->vehicle;
        $msg = "🚖 Sydney Taxi: Driver assigned!\n"
             . "Driver: {$driver->name}\n"
             . "Vehicle: {$vehicle?->display_name} · {$vehicle?->plate_number}\n"
             . "Phone: {$driver->phone}\n"
             . "ETA: ~7 min\n"
             . "Track: sydneytaxi.com.au/track/{$booking->booking_number}";
        return $this->send($booking->customer->phone, $msg);
    }

    public function sendNewRideAlert(\App\Models\Booking $booking): bool
    {
        $msg = "🚨 New ride request!\n"
             . "Booking: {$booking->booking_number}\n"
             . "📍 {$booking->pickup_address}\n"
             . "🏁 {$booking->dropoff_address}\n"
             . "💰 $" . number_format($booking->estimated_fare, 2) . "\n"
             . "Accept in app: sydneytaxi.com.au/driver/rides";
        return $this->send($booking->customer->phone, $msg);
    }
}

// ============================================================
// app/Services/DriverDispatchService.php
// ============================================================
class DriverDispatchService
{
    public function __construct(private SmsService $sms) {}

    /**
     * Find nearest available driver for a booking
     */
    public function findDriver(\App\Models\Booking $booking): void
    {
        // Dispatch asynchronously via queue job
        \App\Jobs\DispatchDriverJob::dispatch($booking)->delay(now()->addSeconds(2));
    }

    /**
     * Find nearest drivers (called by the job)
     */
    public function findNearestDrivers(\App\Models\Booking $booking, int $limit = 5): \Illuminate\Support\Collection
    {
        return \App\Models\User::query()
            ->where('role', 'driver')
            ->where('status', 'active')
            ->whereHas('driverProfile', function ($q) use ($booking) {
                $q->where('is_available', true)
                  ->where('approval_status', 'approved')
                  ->whereHas('vehicle', fn($vq) => $vq->where('status', 'active')
                    ->where('vehicle_type_id', $booking->vehicle_type_id)
                  );
            })
            ->with('driverProfile')
            ->get()
            ->filter(fn($driver) => $driver->driverProfile !== null)
            ->sortBy(function ($driver) use ($booking) {
                return $this->haversine(
                    $driver->driverProfile->current_lat,
                    $driver->driverProfile->current_lng,
                    $booking->pickup_lat,
                    $booking->pickup_lng
                );
            })
            ->take($limit);
    }

    /**
     * Assign a driver to a booking
     */
    public function assignDriver(\App\Models\Booking $booking, \App\Models\User $driver): bool
    {
        $profile = $driver->driverProfile;
        $vehicle = $profile->vehicle;

        if (!$profile || !$vehicle) return false;

        $booking->update([
            'driver_id'   => $driver->id,
            'vehicle_id'  => $vehicle->id,
            'status'      => 'accepted',
            'accepted_at' => now(),
        ]);

        $profile->update(['is_available' => false]);

        // Notify customer
        $this->sms->sendDriverAssigned($booking);

        // Broadcast via Pusher
        event(new \App\Events\DriverAssignedEvent($booking));

        return true;
    }

    private function haversine($lat1, $lng1, $lat2, $lng2): float
    {
        if (!$lat1 || !$lat2) return 9999;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1-$a));
    }
}