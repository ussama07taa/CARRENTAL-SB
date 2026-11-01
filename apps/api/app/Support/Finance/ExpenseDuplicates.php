<?php

namespace App\Support\Finance;

class ExpenseDuplicates
{
    /** Similarity is a warning, not proof that two real invoices are identical. */
    public function check(array $input, ?int $exclude = null, bool $maintenance = false): void
    {
        $supplier = trim($input['supplier'] ?? '');
        if ($supplier === '') {
            return;
        }
        $query = app(ExpenseReadModel::class)->query()->where('cancelled', false)
            ->whereRaw("lower(regexp_replace(trim(supplier),'\\s+',' ','g'))=lower(regexp_replace(trim(?),'\\s+',' ','g'))", [$supplier]);
        if ($exclude !== null) {
            $query->where(fn ($q) => $q->where('source', '<>', 'manual')->orWhere('id', '<>', $exclude));
        }
        $cars = array_values(array_filter(array_column($input['allocations'], 'vehicle_id')));
        $query->where(function ($q) use ($input, $cars, $maintenance) {
            $reference = trim($input['invoice_reference'] ?? '');
            if ($reference !== '') {
                $q->whereRaw('lower(trim(invoice_reference))=lower(?)', [$reference]);
            } else {
                $q->whereRaw('false');
            }
            if ($cars) {
                $q->orWhere(function ($similar) use ($input, $cars, $maintenance) {
                    $similar->where('date', $input['incurred_on'])->where('cost_centimes', $input['amount_centimes']);
                    if (! $maintenance) {
                        $similar->where('source', 'maintenance');
                    }
                    $similar->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(allocations) a WHERE (a->>'vehicle_id')::bigint IN (".implode(',', array_fill(0, count($cars), '?')).'))', $cars);
                });
            }
        });
        $matches = $query->limit(5)->get(['id', 'source', 'vehicle_id', 'title'])->map(fn ($r) => (array) $r)->all();
        if ($matches && ! ($input['duplicate_confirmed'] ?? false)) {
            abort(response()->json(['code' => 'POSSIBLE_DUPLICATE', 'message' => 'Une dépense similaire existe déjà. Ouvrez le dossier proposé pour éviter une seconde saisie.', 'duplicates' => $matches], 409));
        }
    }
}
