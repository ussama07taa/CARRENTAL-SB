<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleLoanDocumentRequest;
use App\Http\Requests\StoreVehicleLoanPaymentRequest;
use App\Http\Requests\StoreVehicleLoanRequest;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleLoanController extends Controller
{
    public function index(Request $request, VehicleLoans $loans): JsonResponse
    {
        $loans->actor($request->user()->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'vehicle_id' => ['nullable', 'integer', 'min:1'], 'filter' => ['nullable', Rule::in(['all', 'overdue', 'upcoming', 'settled', 'cancelled'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $loans->summaries(true);
        if (! empty($input['vehicle_id'])) {
            Vehicle::findOrFail($input['vehicle_id']);
            $query->where('l.vehicle_id', $input['vehicle_id']);
        }
        if ($q = trim($input['q'] ?? '')) {
            $q = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->whereRaw("(v.plate || ' ' || v.make || ' ' || v.model || ' ' || l.lender || ' ' || l.reference) ILIKE ?", [$q]);
        }
        $summary = DB::query()->fromSub((clone $query)->whereNull('lc.id'), 'loans')->selectRaw('COUNT(*) AS loan_count, COALESCE(SUM(remaining_centimes),0) AS remaining_centimes, COALESCE(SUM(overdue_centimes),0) AS overdue_centimes, COALESCE(SUM(month_centimes),0) AS month_centimes, COALESCE(SUM(recorded_paid_centimes),0) AS recorded_paid_centimes, COALESCE(SUM(opening_paid_centimes),0) AS opening_paid_centimes')->first();
        if (($input['filter'] ?? 'all') === 'cancelled') {
            $query->whereNotNull('lc.id');
        } else {
            $query->whereNull('lc.id');
        }
        if (($input['filter'] ?? 'all') === 'overdue') {
            $query->where('s.overdue_centimes', '>', 0);
        }
        if (($input['filter'] ?? 'all') === 'upcoming') {
            $query->where('s.upcoming_centimes', '>', 0);
        }
        if (($input['filter'] ?? 'all') === 'settled') {
            $query->where('s.remaining_centimes', 0);
        }
        $page = $query->orderByRaw('CASE WHEN s.overdue_centimes > 0 THEN 0 WHEN s.remaining_centimes > 0 THEN 1 ELSE 2 END')->orderBy('s.next_due_on')->orderByDesc('l.id')->paginate(15);

        return response()->json(['data' => collect($page->items())->map(fn ($r) => $loans->numbers($r)), 'summary' => $loans->numbers($summary), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'today' => now('Africa/Casablanca')->toDateString()]);
    }

    public function store(StoreVehicleLoanRequest $request, VehicleLoans $loans): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $input = $request->validated();
        $file = $request->file('file');
        unset($input['file']);
        $input['vehicle_id'] = (int) $input['vehicle_id'];
        $input['financed_centimes'] = isset($input['financed_centimes']) ? (int) $input['financed_centimes'] : null;
        foreach ($input['installments'] as &$row) {
            $row['amount_centimes'] = (int) $row['amount_centimes'];
            $row['opening_paid_centimes'] = (int) $row['opening_paid_centimes'];
        }
        unset($row);
        $hash = $loans->hash([...$input, 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null]);
        $path = null;
        try {
            $id = DB::transaction(function () use ($input, $file, $actor, $loans, $hash, &$path) {
                $loans->lock();
                Vehicle::whereKey($input['vehicle_id'])->lockForUpdate()->firstOrFail();
                if ($old = $loans->replay('vehicle_loans', $input['request_key'], $hash)) {
                    return $old->id;
                }
                $lender = trim($input['lender']);
                $reference = trim($input['reference']);
                if ($lender === '' || $reference === '') {
                    throw ValidationException::withMessages([$lender === '' ? 'lender' : 'reference' => 'Ce champ est obligatoire.']);
                }
                $identity = hash('sha256', mb_strtoupper(preg_replace('/\s+/u', '', $lender).'|'.preg_replace('/\s+/u', '', $reference)));
                if ($existing = $loans->query()->where('identity_key', $identity)->first()) {
                    if ($loans->query('vehicle_loan_cancellations')->where('loan_id', $existing->id)->exists()) {
                        throw ValidationException::withMessages(['reference' => 'Ce numéro est conservé dans un contrat annulé. Consultez l’archive ; ne changez pas le numéro réel pour contourner ce contrôle. La reprise d’un contrat annulé n’est pas disponible dans cette étape.']);
                    }
                    throw ValidationException::withMessages(['reference' => 'Ce contrat est déjà enregistré pour cet organisme. Ouvrez le dossier existant pour éviter un doublon.']);
                }
                $previous = '';
                $opening = 0;
                foreach ($input['installments'] as $i => $row) {
                    if ($previous !== '' && $row['due_on'] < $previous) {
                        throw ValidationException::withMessages(["installments.$i.due_on" => 'Les échéances doivent être dans l’ordre chronologique.']);
                    }
                    if (! empty($input['signed_on']) && $row['due_on'] < $input['signed_on']) {
                        throw ValidationException::withMessages(["installments.$i.due_on" => 'Cette échéance précède la date du contrat.']);
                    }
                    if ($row['opening_paid_centimes'] > $row['amount_centimes']) {
                        throw ValidationException::withMessages(["installments.$i.opening_paid_centimes" => 'Le montant déjà réglé ne peut pas dépasser cette échéance.']);
                    }
                    $opening += $row['opening_paid_centimes'];
                    $previous = $row['due_on'];
                }
                if ($opening > 0 && ! ($input['opening_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['opening_confirmed' => 'Confirmez les montants déjà réglés avant le début du suivi. Ils ne créeront pas de nouveaux paiements.']);
                }
                $id = DB::table('vehicle_loans')->insertGetId(['company_id' => Tenant::id(), 'vehicle_id' => $input['vehicle_id'], 'actor_membership_id' => $actor, 'lender' => $lender, 'reference' => $reference, 'identity_key' => $identity, 'signed_on' => $input['signed_on'] ?? null, 'tracking_from' => $input['tracking_from'], 'financed_centimes' => $input['financed_centimes'], 'notes' => $input['notes'] ?? null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $rows = [];
                foreach ($input['installments'] as $i => $row) {
                    $rows[] = ['company_id' => Tenant::id(), 'loan_id' => $id, 'position' => $i + 1, ...$row];
                }
                DB::table('vehicle_loan_installments')->insert($rows);
                $loan = $loans->loan($id);
                $loans->audit($loan, $actor, 'loan_created', ['installment_count' => count($rows), 'total_centimes' => array_sum(array_column($rows, 'amount_centimes')), 'opening_paid_centimes' => $opening, 'tracking_from' => $input['tracking_from']]);
                if ($file) {
                    $loans->document($loan, $actor, $file, $input['request_key'], $hash, $path);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('vehicle_documents')->delete($path);
            }
            throw $e;
        }

        return response()->json(['id' => $id], 201);
    }

    public function show(Request $request, int $loan, VehicleLoans $loans): JsonResponse
    {
        $loans->actor($request->user()->id);
        $data = $loans->summaries(true)->where('l.id', $loan)->firstOrFail();
        $payments = $loans->payments()->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'p.company_id')->on('m.id', '=', 'p.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->join('vehicle_loan_installments as i', function ($j) {
            $j->on('i.company_id', '=', 'p.company_id')->on('i.loan_id', '=', 'p.loan_id')->on('i.id', '=', 'p.installment_id');
        })->where('p.company_id', Tenant::id())->where('p.loan_id', $loan)->orderByDesc('p.id')->selectRaw('CASE WHEN x.id IS NULL THEN COALESCE(c.amount_centimes,p.amount_centimes) ELSE 0 END AS effective_amount_centimes, COALESCE(c.version,0) AS correction_revision, x.id AS cancellation_id')->addSelect(['p.id', 'p.installment_id', 'i.position', 'p.amount_centimes', 'p.paid_on', 'p.method', 'p.reference', 'p.created_at', 'u.name as actor'])->get()->map(fn ($r) => $loans->numbers($r));
        $corrections = DB::table('vehicle_loan_payment_corrections as c')->join('vehicle_loan_payments as p', function ($j) {
            $j->on('p.company_id', '=', 'c.company_id')->on('p.request_key', '=', 'c.payment_key');
        })->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'c.company_id')->on('m.id', '=', 'c.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('c.company_id', Tenant::id())->where('p.loan_id', $loan)->orderBy('c.id')->get(['c.id', 'p.id as payment_id', 'c.version', 'c.before_centimes', 'c.amount_centimes', 'c.reason', 'c.created_at', 'u.name as actor'])->map(fn ($r) => $loans->numbers($r));
        $cancellations = DB::table('vehicle_loan_payment_cancellations as c')->join('vehicle_loan_payments as p', function ($j) {
            $j->on('p.company_id', '=', 'c.company_id')->on('p.request_key', '=', 'c.payment_key');
        })->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'c.company_id')->on('m.id', '=', 'c.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('c.company_id', Tenant::id())->where('p.loan_id', $loan)->orderBy('c.id')->get(['c.id', 'p.id as payment_id', 'c.before_centimes', 'c.reason', 'c.created_at', 'u.name as actor'])->map(fn ($r) => $loans->numbers($r));
        $cancellation = DB::table('vehicle_loan_cancellations as lc')->join('company_memberships as m', function ($j) {
            $j->on('m.company_id', '=', 'lc.company_id')->on('m.id', '=', 'lc.actor_membership_id');
        })->join('users as u', 'u.id', '=', 'm.user_id')->where('lc.company_id', Tenant::id())->where('lc.loan_id', $loan)->first(['lc.id', 'lc.reason', 'lc.before_remaining_centimes', 'lc.created_at', 'u.name as actor']);
        if ($cancellation) {
            $loans->numbers($cancellation);
        }
        $documents = $loans->query('vehicle_loan_documents')->where('loan_id', $loan)->orderByDesc('id')->get(['id', 'mime', 'created_at']);

        return response()->json(['data' => $loans->numbers($data), 'cancellation' => $cancellation, 'installments' => $loans->schedule($loan), 'payments' => $payments, 'corrections' => $corrections, 'cancellations' => $cancellations, 'financial_revision' => $loans->financialRevision($loan), 'documents' => $documents, 'today' => now('Africa/Casablanca')->toDateString()]);
    }

    public function pay(StoreVehicleLoanPaymentRequest $request, int $loan, int $installment, VehicleLoans $loans): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $input = $request->validated();
        $input['amount_centimes'] = (int) $input['amount_centimes'];
        $input['expected_remaining_centimes'] = (int) $input['expected_remaining_centimes'];
        $id = DB::transaction(function () use ($loan, $installment, $actor, $input, $loans) {
            $loans->lock();

            return $loans->pay($loans->loan($loan), $installment, $actor, $input);
        });

        return response()->json(['id' => $id], 201);
    }

    public function document(StoreVehicleLoanDocumentRequest $request, int $loan, VehicleLoans $loans): JsonResponse
    {
        $actor = $loans->actor($request->user()->id);
        $file = $request->file('file');
        $key = $request->validated('request_key');
        $hash = $loans->hash(['loan_id' => $loan, 'file_hash' => hash_file('sha256', $file->getRealPath())]);
        $path = null;
        try {
            $id = DB::transaction(function () use ($loan, $actor, $file, $key, $hash, &$path, $loans) {
                $loans->lock();
                $data = $loans->loan($loan);
                $loans->ensureActive($loan);
                if ($old = $loans->replay('vehicle_loan_documents', $key, $hash)) {
                    return $old->id;
                }

                return $loans->document($data, $actor, $file, $key, $hash, $path);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('vehicle_documents')->delete($path);
            }
            throw $e;
        }

        return response()->json(['id' => $id], 201);
    }

    public function content(Request $request, int $loan, int $document, VehicleLoans $loans): StreamedResponse
    {
        $loans->actor($request->user()->id);
        $loans->loan($loan);
        $row = $loans->query('vehicle_loan_documents')->where('loan_id', $loan)->where('id', $document)->firstOrFail();
        abort_unless(Storage::disk('vehicle_documents')->exists($row->path), 404);
        $ext = match ($row->mime) {
            'application/pdf' => 'pdf','image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        };

        return Storage::disk('vehicle_documents')->download($row->path, 'contrat-credit-'.$loan.'-'.$document.'.'.$ext, ['Content-Type' => $row->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
