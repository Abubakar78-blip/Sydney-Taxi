<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\VehicleType;
use App\Models\Coupon;
use App\Models\CouponUse;
use App\Models\Payment;
use App\Models\DriverEarning;
use App\Services\FareCalculatorService;
use App\Services\StripeService;
use App\Services\SmsService;
use App\Services\DriverDispatchService;
use App\Mail\BookingConfirmationMail;
use App\Mail\BookingCancelledMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private FareCalculatorService $fareCalculator,
        private StripeService $stripe,
        private SmsService $sms,
        private DriverDispatchService $dispatch,
    ) {}

    /**
     * Show booking form / homepage
     */
    public function index()
    {
        $vehicleTypes = VehicleType::where('is_active', true)->orderBy('sort_order')->get();
        return view('home.index', compact('vehicleTypes'));
    }

    /**
     * Estimate fare via AJAX
     */
    public function estimateFare(Request $request)
    {
        $validated = $request->validate([
            'pickup_lat'       => 'required|numeric|between:-90,90',
            'pickup_lng'       => 'required|numeric|between:-180,180',
            'dropoff_lat'      => 'required|numeric|between:-90,90',
            'dropoff_lng'      => 'required|numeric|between:-180,180',
            'vehicle_type'     => 'required|exists:vehicle_types,slug',
            'scheduled_at'     => 'nullable|date',
            'is_airport'       => 'nullable|boolean',
            'coupon_code'      => 'nullable|string|max:50',
        ]);

        $vehicleType = VehicleType::where('slug', $validated['vehicle_type'])->firstOrFail();

        $estimate = $this->fareCalculator->estimate(
            pickupLat:   $validated['pickup_lat'],
            pickupLng:   $validated['pickup_lng'],
            dropoffLat:  $validated['dropoff_lat'],
            dropoffLng:  $validated['dropoff_lng'],
            vehicleType: $vehicleType,
            scheduledAt: isset($validated['scheduled_at']) ? new \DateTime($validated['scheduled_at']) : null,
            isAirport:   (bool)($validated['is_airport'] ?? false),
        );

        // Apply coupon if provided
        $discount = 0;
        $couponValid = false;
        if (!empty($validated['coupon_code']) && Auth::check()) {
            $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))->first();
            if ($coupon && $coupon->isValid() && $estimate['total'] >= $coupon->minimum_fare) {
                $userUses = CouponUse::where('coupon_id', $coupon->id)->where('user_id', Auth::id())->count();
                if ($userUses < $coupon->usage_per_user) {
                    $discount = $coupon->calculateDiscount($estimate['total']);
                    $couponValid = true;
                }
            }
        }

        return response()->json([
            'success' => true,
            'estimate' => [
                ...$estimate,
                'coupon_discount' => $discount,
                'total_after_discount' => max(0, $estimate['total'] - $discount),
                'coupon_valid' => $couponValid,
            ],
        ]);
    }

    /**
     * Validate coupon code
     */
    public function validateCoupon(Request $request)
    {
        $request->validate([
            'code'  => 'required|string|max:50',
            'fare'  => 'required|numeric|min:0',
        ]);

        $coupon = Coupon::where('code', strtoupper($request->code))->first();

        if (!$coupon || !$coupon->isValid()) {
            return response()->json(['valid' => false, 'message' => 'Invalid or expired promo code.']);
        }

        if ($request->fare < $coupon->minimum_fare) {
            return response()->json([
                'valid' => false,
                'message' => "This code requires a minimum fare of $" . number_format($coupon->minimum_fare, 2),
            ]);
        }

        if (Auth::check()) {
            $uses = CouponUse::where('coupon_id', $coupon->id)->where('user_id', Auth::id())->count();
            if ($uses >= $coupon->usage_per_user) {
                return response()->json(['valid' => false, 'message' => 'You have already used this promo code.']);
            }
        }

        $discount = $coupon->calculateDiscount($request->fare);

        return response()->json([
            'valid'    => true,
            'message'  => "✅ {$coupon->name} applied!",
            'discount' => $discount,
            'coupon'   => [
                'id'   => $coupon->id,
                'name' => $coupon->name,
                'type' => $coupon->type,
            ],
        ]);
    }

    /**
     * Create a new booking
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pickup_address'       => 'required|string|max:500',
            'pickup_suburb'        => 'nullable|string|max:100',
            'pickup_lat'           => 'required|numeric|between:-90,90',
            'pickup_lng'           => 'required|numeric|between:-180,180',
            'dropoff_address'      => 'required|string|max:500',
            'dropoff_suburb'       => 'nullable|string|max:100',
            'dropoff_lat'          => 'required|numeric|between:-90,90',
            'dropoff_lng'          => 'required|numeric|between:-180,180',
            'vehicle_type_id'      => 'required|exists:vehicle_types,id',
            'booking_type'         => 'required|in:now,scheduled',
            'scheduled_at'         => 'nullable|required_if:booking_type,scheduled|date|after:now',
            'payment_method'       => 'required|in:card,cash,apple_pay,google_pay',
            'stripe_payment_intent_id' => 'nullable|string',
            'coupon_code'          => 'nullable|string|max:50',
            'passengers'           => 'nullable|integer|min:1|max:10',
            'special_instructions' => 'nullable|string|max:500',
            'baby_seat_required'   => 'nullable|boolean',
            'flight_number'        => 'nullable|string|max:20',
            'is_airport_pickup'    => 'nullable|boolean',
            'is_airport_dropoff'   => 'nullable|boolean',
        ]);

        $vehicleType = VehicleType::findOrFail($validated['vehicle_type_id']);
        $customer = Auth::user();

        // Calculate final fare
        $estimate = $this->fareCalculator->estimate(
            pickupLat:   $validated['pickup_lat'],
            pickupLng:   $validated['pickup_lng'],
            dropoffLat:  $validated['dropoff_lat'],
            dropoffLng:  $validated['dropoff_lng'],
            vehicleType: $vehicleType,
            scheduledAt: isset($validated['scheduled_at']) ? new \DateTime($validated['scheduled_at']) : null,
            isAirport:   ($validated['is_airport_pickup'] ?? false) || ($validated['is_airport_dropoff'] ?? false),
        );

        DB::beginTransaction();
        try {
            // Handle coupon
            $couponId = null;
            $couponDiscount = 0;
            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', strtoupper($validated['coupon_code']))->lockForUpdate()->first();
                if ($coupon && $coupon->isValid()) {
                    $couponDiscount = $coupon->calculateDiscount($estimate['total']);
                    $couponId = $coupon->id;
                }
            }

            $babySeatFee = ($validated['baby_seat_required'] ?? false) ? 5.00 : 0;
            $estimatedFare = max(0, $estimate['total'] - $couponDiscount + $babySeatFee);

            // Create booking
            $booking = Booking::create([
                'customer_id'          => $customer->id,
                'vehicle_type_id'      => $vehicleType->id,
                'coupon_id'            => $couponId,
                'pickup_address'       => $validated['pickup_address'],
                'pickup_suburb'        => $validated['pickup_suburb'] ?? null,
                'pickup_lat'           => $validated['pickup_lat'],
                'pickup_lng'           => $validated['pickup_lng'],
                'dropoff_address'      => $validated['dropoff_address'],
                'dropoff_suburb'       => $validated['dropoff_suburb'] ?? null,
                'dropoff_lat'          => $validated['dropoff_lat'],
                'dropoff_lng'          => $validated['dropoff_lng'],
                'booking_type'         => $validated['booking_type'],
                'scheduled_at'         => $validated['scheduled_at'] ?? null,
                'estimated_distance'   => $estimate['distance_km'],
                'estimated_duration'   => $estimate['duration_min'],
                'base_fare'            => $estimate['base_fare'],
                'distance_fare'        => $estimate['distance_fare'],
                'time_fare'            => $estimate['time_fare'],
                'surcharge'            => $estimate['surcharge'] + $babySeatFee,
                'surge_multiplier'     => $estimate['surge_multiplier'],
                'coupon_discount'      => $couponDiscount,
                'estimated_fare'       => $estimatedFare,
                'status'               => 'pending',
                'payment_status'       => 'pending',
                'payment_method'       => $validated['payment_method'],
                'special_instructions' => $validated['special_instructions'] ?? null,
                'is_airport_pickup'    => $validated['is_airport_pickup'] ?? false,
                'is_airport_dropoff'   => $validated['is_airport_dropoff'] ?? false,
                'flight_number'        => $validated['flight_number'] ?? null,
                'passengers'           => $validated['passengers'] ?? 1,
                'baby_seat_required'   => $validated['baby_seat_required'] ?? false,
            ]);

            // Process payment
            if ($validated['payment_method'] !== 'cash') {
                $paymentResult = $this->stripe->createPaymentIntent(
                    amount:        (int)($estimatedFare * 100),
                    currency:      'aud',
                    customerId:    $customer->stripe_customer_id,
                    metadata:      ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number],
                    paymentMethod: $validated['stripe_payment_intent_id'] ?? null,
                );

                Payment::create([
                    'booking_id'               => $booking->id,
                    'user_id'                  => $customer->id,
                    'stripe_payment_intent_id' => $paymentResult['payment_intent_id'],
                    'amount'                   => $estimatedFare,
                    'currency'                 => 'AUD',
                    'status'                   => $paymentResult['status'],
                    'method'                   => $validated['payment_method'],
                ]);
            }

            // Consume coupon
            if ($couponId) {
                CouponUse::create([
                    'coupon_id'       => $couponId,
                    'user_id'         => $customer->id,
                    'booking_id'      => $booking->id,
                    'discount_amount' => $couponDiscount,
                ]);
                Coupon::where('id', $couponId)->increment('used_count');
            }

            DB::commit();

            // Dispatch driver (async)
            $this->dispatch->findDriver($booking);

            // Send confirmations
            Mail::to($customer->email)->queue(new BookingConfirmationMail($booking));
            $this->sms->send($customer->phone, $this->buildSmsMessage($booking));

            return response()->json([
                'success'        => true,
                'booking_number' => $booking->booking_number,
                'booking_id'     => $booking->id,
                'estimated_fare' => $estimatedFare,
                'message'        => 'Booking confirmed! Driver is being assigned.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Booking creation failed', ['error' => $e->getMessage(), 'user' => $customer->id]);
            return response()->json(['success' => false, 'message' => 'Booking failed. Please try again.'], 500);
        }
    }

    /**
     * Show booking details
     */
    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);
        $booking->load(['vehicleType', 'driver.driverProfile.vehicle', 'payment', 'review', 'tracking' => fn($q) => $q->latest()->limit(50)]);
        return view('customer.booking-detail', compact('booking'));
    }

    /**
     * Customer's booking history
     */
    public function history(Request $request)
    {
        $bookings = Auth::user()->bookingsAsCustomer()
            ->with(['vehicleType', 'driver', 'review'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->where('booking_number', 'like', "%{$request->search}%")
                ->orWhere('pickup_address', 'like', "%{$request->search}%")
                ->orWhere('dropoff_address', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(15);

        return view('customer.bookings', compact('bookings'));
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, Booking $booking)
    {
        $this->authorize('cancel', $booking);

        if (!in_array($booking->status, ['pending', 'accepted'])) {
            return response()->json(['success' => false, 'message' => 'This booking cannot be cancelled.'], 422);
        }

        $request->validate(['reason' => 'nullable|string|max:500']);

        $cancellationFee = 0;
        if ($booking->status === 'accepted' && $booking->accepted_at?->diffInMinutes(now()) > 2) {
            $cancellationFee = $booking->vehicleType->cancellation_fee;
        }

        DB::transaction(function () use ($booking, $request, $cancellationFee) {
            $booking->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancelled_by'        => Auth::id(),
                'cancellation_reason' => $request->reason,
            ]);

            if ($cancellationFee > 0 && $booking->payment) {
                $refundAmount = $booking->payment->amount - $cancellationFee;
                if ($refundAmount > 0) {
                    $this->stripe->refund($booking->payment->stripe_charge_id, (int)($refundAmount * 100));
                    $booking->payment->update([
                        'status'        => 'refunded',
                        'refund_amount' => $refundAmount,
                        'refunded_at'   => now(),
                    ]);
                }
            } elseif ($booking->payment && $booking->payment->amount > 0) {
                $this->stripe->refund($booking->payment->stripe_charge_id);
                $booking->payment->update(['status' => 'refunded', 'refunded_at' => now()]);
            }
        });

        // Notify driver if assigned
        if ($booking->driver) {
            $this->sms->send($booking->driver->phone, "Booking {$booking->booking_number} was cancelled by the customer.");
        }

        Mail::to($booking->customer->email)->queue(new BookingCancelledMail($booking));

        return response()->json([
            'success'          => true,
            'message'          => 'Booking cancelled successfully.',
            'cancellation_fee' => $cancellationFee,
        ]);
    }

    /**
     * Get real-time tracking data
     */
    public function tracking(Booking $booking)
    {
        $this->authorize('view', $booking);

        $driver = $booking->driver?->driverProfile;
        $lastTracking = $booking->tracking()->latest()->first();

        return response()->json([
            'status'         => $booking->status,
            'driver_lat'     => $driver?->current_lat,
            'driver_lng'     => $driver?->current_lng,
            'driver_name'    => $booking->driver?->name,
            'driver_phone'   => $booking->driver?->phone,
            'vehicle'        => $booking->vehicle?->display_name,
            'plate'          => $booking->vehicle?->plate_number,
            'eta_minutes'    => $this->calculateEta($driver, $booking),
            'last_update'    => $driver?->location_updated_at?->diffForHumans(),
        ]);
    }

    /**
     * Submit a review for a completed booking
     */
    public function review(Request $request, Booking $booking)
    {
        $this->authorize('review', $booking);

        if (!$booking->isCompleted()) {
            return response()->json(['success' => false, 'message' => 'Can only review completed bookings.'], 422);
        }

        if ($booking->review) {
            return response()->json(['success' => false, 'message' => 'You have already reviewed this booking.'], 422);
        }

        $request->validate([
            'rating'  => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $review = $booking->review()->create([
            'customer_id' => Auth::id(),
            'driver_id'   => $booking->driver_id,
            'rating'      => $request->rating,
            'comment'     => $request->comment,
        ]);

        // Update driver's aggregate rating
        if ($booking->driver_id) {
            $profile = $booking->driver->driverProfile;
            $newTotal = $profile->rating_count + 1;
            $newRating = (($profile->rating * $profile->rating_count) + $request->rating) / $newTotal;
            $profile->update(['rating' => round($newRating, 2), 'rating_count' => $newTotal]);
        }

        return response()->json(['success' => true, 'message' => 'Thank you for your review!']);
    }

    private function buildSmsMessage(Booking $booking): string
    {
        return "✅ Sydney Taxi: Booking {$booking->booking_number} confirmed!\n"
            . "📍 From: {$booking->pickup_address}\n"
            . "🏁 To: {$booking->dropoff_address}\n"
            . "💰 Est. fare: $" . number_format($booking->estimated_fare, 2) . "\n"
            . "Track: https://sydneytaxi.com.au/track/{$booking->booking_number}";
    }

    private function calculateEta(?object $driver, Booking $booking): ?int
    {
        if (!$driver || !$booking->isPending() && !$booking->isActive()) return null;
        if ($booking->status === 'in_progress') return null;

        $distKm = haversine(
            $driver->current_lat, $driver->current_lng,
            $booking->pickup_lat, $booking->pickup_lng
        );
        return max(1, (int)($distKm * 2.5)); // ~24km/h average
    }
}

// ─── Helper ──────────────────────────────────────────────────────
if (!function_exists('haversine')) {
    function haversine($lat1, $lng1, $lat2, $lng2): float {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
    }
}