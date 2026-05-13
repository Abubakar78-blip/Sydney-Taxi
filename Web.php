<?php
// ============================================================
// routes/web.php
// ============================================================
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Customer\BookingController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Driver\DriverController;
use App\Http\Controllers\Driver\RideController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BookingAdminController;
use App\Http\Controllers\Admin\DriverAdminController;
use App\Http\Controllers\Admin\CustomerAdminController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\FleetController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\InvoiceController;

// ─── PUBLIC ROUTES ────────────────────────────────────────────
Route::get('/', [BookingController::class, 'index'])->name('home');
Route::get('/about', fn() => view('pages.about'))->name('about');
Route::get('/fleet', fn() => view('pages.fleet'))->name('fleet');
Route::get('/contact', fn() => view('pages.contact'))->name('contact');
Route::get('/safety', fn() => view('pages.safety'))->name('safety');
Route::get('/drive-with-us', fn() => view('pages.drive'))->name('drive');

// Fare estimation (public, no auth required)
Route::post('/estimate-fare', [BookingController::class, 'estimateFare'])->name('fare.estimate');
Route::post('/validate-coupon', [BookingController::class, 'validateCoupon'])->name('coupon.validate');

// Live tracking (public link from SMS)
Route::get('/track/{bookingNumber}', [BookingController::class, 'publicTracking'])->name('tracking.public');

// ─── AUTH ROUTES ──────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login',    [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',   [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register',[AuthController::class, 'register']);
    Route::get('/forgot-password',  [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password',        [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// ─── CUSTOMER ROUTES ──────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:customer'])->prefix('/')->name('customer.')->group(function () {
    Route::get('/dashboard',  [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile',    [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile',    [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');

    // Bookings
    Route::get('/bookings',            [BookingController::class, 'history'])->name('bookings');
    Route::post('/bookings',           [BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{booking}',  [BookingController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('/bookings/{booking}/review', [BookingController::class, 'review'])->name('bookings.review');
    Route::get('/bookings/{booking}/tracking',[BookingController::class, 'tracking'])->name('bookings.tracking');
    Route::get('/bookings/{booking}/invoice', [InvoiceController::class, 'generate'])->name('bookings.invoice');

    // Saved addresses
    Route::get('/addresses',            [ProfileController::class, 'addresses'])->name('addresses');
    Route::post('/addresses',           [ProfileController::class, 'storeAddress'])->name('addresses.store');
    Route::delete('/addresses/{address}', [ProfileController::class, 'deleteAddress'])->name('addresses.delete');

    // Stripe
    Route::get('/stripe/setup-intent', [ProfileController::class, 'createSetupIntent'])->name('stripe.setup');
    Route::post('/stripe/payment-methods', [ProfileController::class, 'addPaymentMethod'])->name('stripe.pm.add');
});

// ─── DRIVER ROUTES ────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:driver', 'driver.approved'])->prefix('driver')->name('driver.')->group(function () {
    Route::get('/dashboard',          [DriverController::class, 'dashboard'])->name('dashboard');
    Route::post('/availability',      [DriverController::class, 'toggleAvailability'])->name('availability');
    Route::post('/location',          [DriverController::class, 'updateLocation'])->name('location');
    Route::get('/earnings',           [DriverController::class, 'earnings'])->name('earnings');
    Route::get('/profile',            [DriverController::class, 'profile'])->name('profile');
    Route::put('/profile',            [DriverController::class, 'updateProfile'])->name('profile.update');

    // Ride management
    Route::get('/rides',              [RideController::class, 'index'])->name('rides');
    Route::post('/rides/{booking}/accept',  [RideController::class, 'accept'])->name('rides.accept');
    Route::post('/rides/{booking}/reject',  [RideController::class, 'reject'])->name('rides.reject');
    Route::post('/rides/{booking}/arrived', [RideController::class, 'arrived'])->name('rides.arrived');
    Route::post('/rides/{booking}/start',   [RideController::class, 'start'])->name('rides.start');
    Route::post('/rides/{booking}/complete',[RideController::class, 'complete'])->name('rides.complete');
    Route::get('/rides/{booking}',          [RideController::class, 'show'])->name('rides.show');
});

// Driver registration (no 'approved' check yet)
Route::middleware(['auth', 'role:driver'])->prefix('driver')->group(function () {
    Route::get('/onboarding',  [DriverController::class, 'onboarding'])->name('driver.onboarding');
    Route::post('/onboarding', [DriverController::class, 'submitOnboarding'])->name('driver.onboarding.submit');
    Route::get('/pending',     [DriverController::class, 'pending'])->name('driver.pending');
});

// ─── ADMIN ROUTES ─────────────────────────────────────────────
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');

    // Bookings
    Route::resource('bookings', BookingAdminController::class)->only(['index', 'show', 'destroy']);
    Route::post('bookings/{booking}/assign-driver', [BookingAdminController::class, 'assignDriver'])->name('bookings.assign');
    Route::post('bookings/{booking}/complete',      [BookingAdminController::class, 'complete'])->name('bookings.complete');
    Route::post('bookings/{booking}/refund',        [BookingAdminController::class, 'refund'])->name('bookings.refund');
    Route::get('bookings/export',                   [BookingAdminController::class, 'export'])->name('bookings.export');

    // Drivers
    Route::resource('drivers', DriverAdminController::class)->only(['index', 'show', 'edit', 'update', 'destroy']);
    Route::post('drivers/{driver}/approve', [DriverAdminController::class, 'approve'])->name('drivers.approve');
    Route::post('drivers/{driver}/reject',  [DriverAdminController::class, 'reject'])->name('drivers.reject');
    Route::post('drivers/{driver}/suspend', [DriverAdminController::class, 'suspend'])->name('drivers.suspend');

    // Customers
    Route::resource('customers', CustomerAdminController::class)->only(['index', 'show', 'edit', 'update']);
    Route::post('customers/{customer}/suspend', [CustomerAdminController::class, 'suspend'])->name('customers.suspend');

    // Fleet
    Route::resource('fleet', FleetController::class);
    Route::post('fleet/{vehicle}/assign-driver', [FleetController::class, 'assignDriver'])->name('fleet.assign');

    // Pricing
    Route::get('pricing',            [PricingController::class, 'index'])->name('pricing.index');
    Route::post('pricing/vehicle-types/{type}', [PricingController::class, 'updateVehicleType'])->name('pricing.vehicle');
    Route::resource('pricing/rules', PricingController::class, ['as' => 'pricing'])->only(['store', 'update', 'destroy']);

    // Coupons
    Route::resource('coupons', CouponController::class);
    Route::post('coupons/{coupon}/toggle', [CouponController::class, 'toggle'])->name('coupons.toggle');

    // Analytics
    Route::get('analytics',           [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/revenue',   [AnalyticsController::class, 'revenue'])->name('analytics.revenue');
    Route::get('analytics/drivers',   [AnalyticsController::class, 'drivers'])->name('analytics.drivers');
    Route::get('analytics/bookings',  [AnalyticsController::class, 'bookings'])->name('analytics.bookings');
});

// ─── STRIPE WEBHOOK ───────────────────────────────────────────
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('stripe.webhook');

// ─── INVOICE ──────────────────────────────────────────────────
Route::get('/invoice/{booking}', [InvoiceController::class, 'generate'])
    ->middleware('auth')
    ->name('invoice.generate');

// ─── SEO ──────────────────────────────────────────────────────
Route::get('/sitemap.xml', fn() => response()->file(public_path('sitemap.xml')))->name('sitemap');
Route::get('/robots.txt', fn() => response("User-agent: *\nAllow: /\nSitemap: " . url('/sitemap.xml'))->header('Content-Type', 'text/plain'));