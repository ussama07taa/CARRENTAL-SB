<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveExpenseDocumentRequest;
use App\Http\Requests\SaveExpenseMovementRequest;
use App\Http\Requests\SaveExpenseRequest;
use App\Support\ExcelExchange;
use App\Support\Finance\ExpenseOverview;
use App\Support\Finance\Expenses;
use App\Support\Finance\FinancialSnapshot;
use App\Support\Finance\PayablesOverview;
use App\Support\Vehicles\VehicleLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    public function index(Request $request, Expenses $service): JsonResponse
    {
        $service->actor($request->user()->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in([...array_keys(Expenses::CATEGORIES), 'maintenance'])], 'document' => ['nullable', Rule::in(['missing', 'attached'])], 'vehicle_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')], 'tab' => ['nullable', Rule::in(['all', 'unpaid', 'maintenance', 'manual', 'paid', 'pricing', 'credit', 'cancelled'])], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(FinancialSnapshot::read(fn () => app(ExpenseOverview::class)->index($input)));
    }

    public function export(Request $request, Expenses $service, ExcelExchange $excel)
    {
        // Same manager authorization and validated filters as the listing, on every page.
        $service->actor($request->user()->id);
        $path = DB::transaction(function () use ($request, $service, $excel) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $rows = [];
            $page = 1;
            $states = ['paid' => 'Payée', 'partial' => 'Partiellement payée', 'unpaid' => 'À payer', 'overdue' => 'Paiement en retard', 'pricing' => 'Prix à confirmer', 'advance' => 'Avance · prix à confirmer', 'credit' => 'Somme à récupérer', 'cancelled' => 'Annulée'];
            $money = fn ($v) => intdiv((int) $v, 100).','.str_pad((string) ((int) $v % 100), 2, '0', STR_PAD_LEFT);
            do {
                $request->merge(['page' => $page]);
                $listing = $this->index($request, $service)->getData(true);
                if ($listing['total'] > 10000) {
                    throw ValidationException::withMessages(['export' => 'Plus de 10 000 lignes. Affinez les filtres avant de réessayer.']);
                }
                foreach ($listing['data'] as $v) {
                    $cars = array_map(fn ($a) => $a['vehicle'] ? $a['vehicle']['make'].' '.$a['vehicle']['model'].' · '.$a['vehicle']['plate'] : 'Agence', $v['allocations']);
                    $rows[] = [$v['key'], $v['source'] === 'maintenance' ? 'Maintenance' : 'Dépense', $v['title'], $listing['categories'][$v['category']] ?? 'Entretien / maintenance', $v['supplier'], $v['invoice_reference'] ?? '', $v['date'], $v['due_on'] ?? '', implode(' / ', $cars), $v['cost_final'] ? 'Confirmé' : 'Provisoire', $money($v['cost_centimes']), $money($v['confirmed_cost_centimes']), $money($v['paid_centimes']), $money($v['balance_centimes']), $money($v['credit_centimes']), $states[$v['state']] ?? $v['state'], $v['has_document'] ? 'Joint' : 'Manquant', $v['vehicle_cost_centimes'] === null ? '' : $money($v['vehicle_cost_centimes'])];
                }
                $page++;
            } while ($page <= $listing['last_page']);
            $filters = $request->only(['q', 'vehicle_id', 'from', 'to', 'tab', 'category', 'document']);
            $guide = 'BEN SBIH — Export des dépenses
Exporté le '.now('Africa/Casablanca')->format('d/m/Y H:i').' (Maroc).
'.$listing['total'].' ligne(s), toutes les pages des filtres sélectionnés.
Filtres : '.json_encode($filters, JSON_UNESCAPED_UNICODE).'
Montants en MAD, conservés en texte décimal exact comme les autres exports.
Les dates filtrent la dépense, pas les paiements. Paiements nets et soldes cumulés à ce jour.
Une facture partagée figure une seule fois : paiements et solde de la facture entière.
La colonne Part voiture ne concerne que le véhicule filtré.
Coût provisoire ≠ coût confirmé. Une avance n’est pas nécessairement remboursable.
Justificatif joint ne signifie pas paiement effectué.
';
            foreach (['confirmed_cost_centimes' => 'Coût confirmé', 'paid_centimes' => 'Net payé', 'balance_centimes' => 'Reste à payer', 'credit_centimes' => 'Avances / sommes à récupérer'] as $key => $label) {
                $guide .= $label.' : '.$money($listing['summary'][$key]).' MAD
';
            }

            return $excel->write(['Référence', 'Origine', 'Objet', 'Catégorie', 'Fournisseur', 'N° facture', 'Date de dépense', 'Échéance', 'Affectation', 'État du coût', 'Coût (MAD)', 'Coût confirmé (MAD)', 'Net payé (MAD)', 'Reste à payer (MAD)', 'Avance / à récupérer (MAD)', 'État', 'Justificatif', 'Part voiture filtrée (MAD)'], $rows, 'Dépenses', $guide);
        });

        return response()->download($path, 'depenses-'.now('Africa/Casablanca')->format('Y-m-d').'.xlsx')->deleteFileAfterSend(true);
    }

    public function payables(Request $request, Expenses $service): JsonResponse
    {
        $service->actor($request->user()->id);
        app(VehicleLoans::class)->actor($request->user()->id);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'vehicle_id' => ['nullable', 'integer', 'min:1'], 'window' => ['nullable', Rule::in(['30days', 'overdue', 'all'])], 'source' => ['nullable', Rule::in(['all', 'expenses', 'loan', 'purchase'])], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(FinancialSnapshot::read(fn () => app(PayablesOverview::class)->index($input)));
    }

    public function show(Request $request, Expenses $service, int $expense): JsonResponse
    {
        $service->actor($request->user()->id);

        return response()->json(FinancialSnapshot::read(fn () => ['data' => $service->detail($expense)]));
    }

    public function store(SaveExpenseRequest $request, Expenses $service): JsonResponse
    {
        $id = $service->save(null, $service->actor($request->user()->id), $request->validated());

        return response()->json(['id' => $id, 'data' => $service->detail($id)], 201);
    }

    public function update(SaveExpenseRequest $request, Expenses $service, int $expense): JsonResponse
    {
        $service->save($expense, $service->actor($request->user()->id), $request->validated());

        return response()->json(['id' => $expense, 'data' => $service->detail($expense)], 201);
    }

    public function movement(SaveExpenseMovementRequest $request, Expenses $service, int $expense): JsonResponse
    {
        $id = $service->movement($expense, $service->actor($request->user()->id), $request->validated());

        return response()->json(['id' => $id, 'data' => $service->detail($expense)], 201);
    }

    public function cancel(Request $request, Expenses $service, int $expense): JsonResponse
    {
        $actor = $service->actor($request->user()->id);
        $input = $request->validate(['reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted'], 'expected_revision' => ['required', 'string', 'max:64'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $service->cancel($expense, $actor, $input);

        return response()->json(['data' => $service->detail($expense)], 201);
    }

    public function document(SaveExpenseDocumentRequest $request, Expenses $service, int $expense): JsonResponse
    {
        $id = $service->document($expense, $service->actor($request->user()->id), $request->file('file'), $request->validated('request_key'));

        return response()->json(['id' => $id, 'data' => $service->detail($expense)], 201);
    }

    public function content(Request $request, Expenses $service, int $expense, int $document): Response
    {
        $service->actor($request->user()->id);
        $service->detail($expense);
        $doc = $service->query('expense_documents')->where('expense_id', $expense)->where('id', $document)->firstOrFail();
        $disk = Storage::disk('expense_documents');
        abort_unless($disk->exists($doc->path), 404, 'Justificatif introuvable.');
        $bytes = $disk->get($doc->path);
        abort_unless(hash_equals($doc->checksum, hash('sha256', $bytes)), 409, 'Ce justificatif nécessite une vérification.');

        return response($bytes)->header('Content-Type', $doc->mime)->header('Content-Disposition', 'inline; filename="justificatif-'.$doc->id.'.'.($doc->mime === 'application/pdf' ? 'pdf' : 'image').'"')->header('X-Content-Type-Options', 'nosniff');
    }
}
