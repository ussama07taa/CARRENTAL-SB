<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Customers\CustomerFields;
use App\Support\Reservations\PublicBookingReceipt;
use App\Support\Reservations\RequestAvailability;
use App\Support\Reservations\RequestPeriod;
use App\Support\Reservations\TemporaryHold;
use App\Support\Reservations\VehiclePricing;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicWebsiteController extends Controller
{
    private function within(callable $fn): mixed
    {
        $id = (int) config('public_site.company_id');
        abort_unless($id > 0, 503, 'PUBLIC_SITE_NOT_CONFIGURED');

        return Tenant::run($id, $fn);
    }

    private function vehicle(int $id): Vehicle
    {
        return Vehicle::where('published', true)->findOrFail($id);
    }

    private function photos(int $id): Collection
    {
        // Select the latest version BEFORE validating it. Never resurrect an old slot.
        return VehiclePhoto::where('vehicle_id', $id)->orderByDesc('version')->get()->unique('slot')
            ->filter(fn ($p) => in_array($p->slot, VehiclePhoto::SLOTS, true) && $this->usable($p))
            ->sortBy(fn ($p) => array_search($p->slot, VehiclePhoto::SLOTS, true))->values();
    }

    private function usable(VehiclePhoto $p): bool
    {
        if (! in_array($p->mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }
        try {
            $disk = Storage::disk($p->disk);
            if (! $disk->exists($p->path) || $p->size <= 0) {
                return false;
            }
            $info = @getimagesizefromstring($disk->get($p->path));

            return $info && $info[0] > 0 && $info[1] > 0 && $info['mime'] === $p->mime;
        } catch (\Throwable) {
            return false;
        }
    }

    private function gallery(int $id, Collection $photos): array
    {
        return $photos->map(fn ($p) => ['slot' => $p->slot, 'image' => "/api/public/photo/{$id}/{$p->slot}?v={$p->version}"])->all();
    }

    public function show(int $vehicle): mixed
    {
        return $this->within(function () use ($vehicle) {
            $v = $this->vehicle($vehicle);
            $photos = $this->photos($vehicle);
            abort_if($photos->isEmpty(), 404);
            $gallery = $this->gallery($vehicle, $photos);
            $rate = app(VehiclePricing::class)->normal($vehicle);

            return response()->json(['data' => [...$v->only(['id', 'make', 'model', 'year', 'fuel', 'transmission', 'seats', 'doors', 'equipment', 'public_description', 'custom_equipment']),
                'daily_centimes' => $rate['daily_centimes'], 'minimum_days' => $rate['minimum_days'], 'image' => $gallery[0]['image'], 'gallery' => $gallery]])->header('Cache-Control', 'no-store');
        });
    }

    public function calendar(int $vehicle): mixed
    {
        return $this->within(function () use ($vehicle) {
            $this->vehicle($vehicle);
            $holds = app(TemporaryHold::class);
            $buffer = $holds->preparationMinutes();
            $intervals = $holds->active()->where('vehicle_id', $vehicle)->filter(fn ($r) => $r->blocked_until > now())->map(fn ($r) => [
                'start' => ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
                'end' => $r->blocked_until->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
                'latest_return' => ($r->assignment_starts_at ?? $r->starts_at)->copy()->subMinutes($buffer)->setTimezone(RequestPeriod::TIMEZONE)->format('Y-m-d\TH:i'),
            ])->values();
            // Public occupancy only: no customer, reference, payment or internal reason.
            return response()->json(['intervals' => $intervals,
                'unavailable' => in_array($vehicle, app(RequestAvailability::class)->blockedVehicles(), true),
            ])->header('Cache-Control', 'no-store');
        });
    }

    public function catalog(Request $request): mixed
    {
        return $this->within(function () use ($request) {
            $period = $request->has('starts_local') ? app(RequestPeriod::class)->validate($request) : null;
            $blocked = $period ? app(RequestAvailability::class)->blockingReasons($period['starts_at'], $period['ends_at']) : [];
            $rows = Vehicle::where('published', true)->orderBy('id')->get()->map(function ($v) use ($period, $blocked) {
                $photos = $this->photos($v->id);
                if ($photos->isEmpty()) {
                    return null;
                }
                $gallery = $this->gallery($v->id, $photos);
                $rate = app(VehiclePricing::class)->normal($v->id);
                $quote = null;
                if ($period && ! isset($blocked[$v->id])) {
                    try {
                        $quote = app(VehiclePricing::class)->simulate($v->id, $period);
                    } catch (HttpException $e) {
                        if ($e->getStatusCode() !== 422) {
                            throw $e;
                        }
                    }
                }

                return [...$v->only(['id', 'make', 'model', 'year', 'fuel', 'transmission', 'seats', 'doors', 'equipment', 'public_description', 'custom_equipment']), 'daily_centimes' => $rate['daily_centimes'], 'minimum_days' => $rate['minimum_days'],
                    'image' => $gallery[0]['image'], 'gallery' => $gallery, 'available' => $period ? (! isset($blocked[$v->id]) && $quote !== null) : null,
                    'quote' => $quote ? ['total_centimes' => $quote['total_centimes'], 'version_id' => $quote['version_id']] : null];
            })->filter()->values();

            return response()->json(['data' => $rows, 'ready' => Schema::hasColumn('reservations', 'website_request'), 'demo' => ! app()->environment('production')])->header('Cache-Control', 'no-store');
        });
    }

    public function photo(int $vehicle, string $slot = 'cover'): mixed
    {
        return $this->within(function () use ($vehicle, $slot) {
            $this->vehicle($vehicle);
            abort_unless(in_array($slot, VehiclePhoto::SLOTS, true), 404);
            $p = VehiclePhoto::where('vehicle_id', $vehicle)->where('slot', $slot)->latest('version')->firstOrFail();
            abort_unless($this->usable($p), 404);

            return Storage::disk($p->disk)->response($p->path, null, ['Content-Type' => $p->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
        });
    }

    public function store(Request $request): mixed
    {
        return $this->within(function () use ($request) {
            abort_unless(Schema::hasColumn('reservations', 'website_request'), 503, 'PUBLIC_SITE_NOT_READY');
            $in = $request->validate([
                'request_key' => ['required', 'uuid'], 'vehicle_id' => ['required', 'integer'], 'starts_local' => ['required', 'date_format:Y-m-d\TH:i'], 'ends_local' => ['required', 'date_format:Y-m-d\TH:i'],
                'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{8,20}$/'], 'email' => ['nullable', 'email', 'max:254'],
                'language' => ['required', Rule::in(['fr', 'ar', 'en', 'es'])], 'pickup' => ['required', Rule::in(['hotel', 'airport', 'city', 'agency'])], 'dropoff' => ['required', Rule::in(['hotel', 'airport', 'city', 'agency'])],
                'notes' => ['nullable', 'string', 'max:1000'], 'payment_method' => ['sometimes', Rule::in(['agency', 'transfer'])],
                'pickup_detail' => ['required_if:pickup,hotel', 'nullable', 'string', 'max:200'], 'dropoff_detail' => ['required_if:dropoff,hotel', 'nullable', 'string', 'max:200'],
                'flight' => ['nullable', 'string', 'max:60'], 'quoted_centimes' => ['required', 'integer', 'min:1'], 'quote_version' => ['required', 'integer'],
                'consent' => ['required', 'accepted'], 'website' => ['nullable', 'max:0'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'status' => ['prohibited'],
            ]);
            $hash = hash('sha256', json_encode($in));
            [$reservation,$created] = DB::transaction(function () use ($request, $in, $hash) {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $old = Reservation::where('request_key', $in['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'REQUEST_KEY_REUSED');

                    return [$old, false];
                }
                $v = $this->vehicle((int) $in['vehicle_id']);
                abort_if($this->photos($v->id)->isEmpty(), 404);
                $p = app(RequestPeriod::class)->validate($request);
                abort_if(CarbonImmutable::parse($p['starts_at'])->isPast(), 422, 'PAST_DEPARTURE');
                app(TemporaryHold::class)->expireLocked();
                $blocked = app(RequestAvailability::class)->blockingReasons($p['starts_at'], $p['ends_at']);
                abort_if(isset($blocked[$v->id]), 409, 'UNIT_UNAVAILABLE');
                $quote = app(VehiclePricing::class)->simulate($v->id, $p);
                abort_unless($quote['total_centimes'] === $in['quoted_centimes'] && $quote['version_id'] == $in['quote_version'], 409, 'QUOTE_CHANGED');
                $phone = CustomerFields::phone($in['phone']);
                // Reuse only an exact identity/contact match; never overwrite a private customer file from the website.
                $c = Customer::where('phone', $phone)->where('first_name', trim($in['first_name']))->where('last_name', trim($in['last_name']))->where('email', $in['email'] ?? null)->first();
                if (! $c) {
                    $c = new Customer(collect($in)->only(['first_name', 'last_name', 'email', 'language'])->all());
                    $c->phone = $phone;
                    $c->creation_key = $in['request_key'];
                    $c->creation_hash = $hash;
                    $c->save();
                    DB::table('customer_events')->insert(['company_id' => Tenant::id(), 'customer_id' => $c->id, 'actor_membership_id' => null, 'action' => 'website_created', 'before' => null, 'after' => json_encode(['source' => 'website']), 'created_at' => now()]);
                }
                if ($in['pickup'] === 'agency') { $in['pickup_detail'] = 'Agence — Branes, Tanger'; }
                if ($in['dropoff'] === 'agency') { $in['dropoff_detail'] = 'Agence — Branes, Tanger'; }
                if ($in['pickup'] === 'city') {
                    $in['pickup_detail'] = 'Tanger Ville';
                }
                if ($in['dropoff'] === 'city') {
                    $in['dropoff_detail'] = 'Tanger Ville';
                }
                $details = [...collect($in)->only(['pickup', 'dropoff', 'pickup_detail', 'dropoff_detail', 'flight', 'notes', 'payment_method'])->all(), 'delivery_centimes' => 0, 'quote' => $quote, 'contact' => collect($in)->only(['first_name', 'last_name', 'phone', 'email'])->all(), 'consent_at' => now()->toIso8601String()];
                $r = Reservation::create(['customer_id' => $c->id, 'vehicle_id' => $v->id, 'actor_membership_id' => null, 'status' => 'demande', 'source' => 'website', 'language' => $in['language'], 'website_request' => $details, 'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'], 'request_key' => $in['request_key'], 'request_hash' => $hash]);
                DB::table('reservation_events')->insert(['company_id' => Tenant::id(), 'reservation_id' => $r->id, 'actor_membership_id' => null, 'action' => 'website_request_created', 'data' => json_encode(['source' => 'website', 'language' => $in['language'], 'blocks_vehicle' => false, ...$details]), 'created_at' => now()]);

                return [$r, true];
            });

            return response()->json(app(PublicBookingReceipt::class)->response($reservation, $created), $created ? 201 : 200)->header('Cache-Control', 'no-store');
        });
    }

    public function lookup(Request $request): mixed
    {
        return $this->within(function () use ($request) {
            $in = $request->validate(['reference' => ['required', 'regex:/^DEM-[0-9]{6,12}$/'], 'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);
            $r = Reservation::where('source', 'website')->find((int) substr($in['reference'], 4));
            abort_unless($r && hash_equals(app(PublicBookingReceipt::class)->token($r), $in['token']), 404);
            $v = Vehicle::findOrFail($r->vehicle_id);

            return response()->json(['reference' => app(PublicBookingReceipt::class)->reference($r), 'status' => $r->status,
                'starts_local' => $r->starts_at->setTimezone('Africa/Casablanca')->format('Y-m-d\TH:i'), 'ends_local' => $r->ends_at->setTimezone('Africa/Casablanca')->format('Y-m-d\TH:i'),
                'vehicle' => $v->only(['make', 'model']), 'pickup' => $r->website_request['pickup'], 'dropoff' => $r->website_request['dropoff'],
                'quoted_centimes' => $r->website_request['quote']['total_centimes']])->header('Cache-Control','no-store');
        });
    }
}
