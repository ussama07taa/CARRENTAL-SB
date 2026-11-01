<?php

namespace App\Http\Controllers;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Vehicles\SaveVehicle;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\ExcelExchange;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenanceExcelImport;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExcelExchangeController extends Controller
{
    public function template(Request $request, string $kind, ExcelExchange $excel)
    {
        $guide = "BEN SBIH — Modèle d’import\nRemplissez uniquement la première feuille. Conservez tous les en-têtes.\n* : obligatoire. Maximum 500 lignes, fichier .xlsx de 2 Mo maximum.\nCréation uniquement. Les doublons et erreurs bloquent le lot entier.\nAucune formule. Téléphones, plaques et pièces : conservez le format texte.\nDates : jj/mm/aaaa. Aucun prix, paiement ni publication automatique.\n";
        $guide .= $kind === 'vehicles' ? 'Carburant : diesel, essence, hybride, électrique. Boîte : manuelle, automatique. Kilométrage et date ensemble.' : 'Téléphone : +212… ou numéro marocain à 10 chiffres. Langue : fr, ar, en, es (fr si vide). Pièce : cin ou passport, avec son numéro.';

        if ($kind === 'maintenance') {
            $guide = "BEN SBIH — Historique des interventions terminées\nPremière feuille uniquement, en-têtes inchangés. * : obligatoire.\n500 lignes maximum, fichier .xlsx de 2 Mo maximum. Aucune formule.\nUne ligne = un travail terminé sur une voiture existante (immatriculation exacte).\nDate réelle jj/mm/aaaa, sans date future. Kilométrage entier facultatif.\nTypes : vidange, freins, pneus, batterie, moteur, carrosserie, entretien, réparation.\nCoût non renseigné : aucun prix, paiement ni écriture de caisse importé.\nAucun changement du compteur actuel, du passage au garage ou de la disponibilité.\nDoublons : même voiture, date, type et intitulé. Aucun remplacement automatique.";
        }

        return response()->download($excel->write(array_values($excel->columns($kind)), [], 'Données', $guide), 'modele-'.$kind.'.xlsx')->deleteFileAfterSend(true);
    }

    public function export(Request $request, string $kind, ExcelExchange $excel)
    {
        if ($kind === 'maintenance') {
            return $this->exportMaintenance($request, $excel);
        }
        // Use the same listing authorization, filters and visible fields as the screen.
        $controller = app($kind === 'vehicles' ? VehicleController::class : CustomerController::class);
        $rows = [];
        $page = 1;
        do {
            $request->merge(['page' => $page]);
            $listing = $controller->index($request)->getData(true);
            if ($listing['total'] > 10000) {
                throw ValidationException::withMessages(['export' => 'Plus de 10 000 lignes. Affinez les filtres avant de réessayer.']);
            }
            foreach ($listing['data'] as $v) {
                if ($kind === 'vehicles') {
                    $amount = $v['normal_daily_centimes'];
                    $price = $amount === null ? '' : intdiv($amount, 100).','.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
                    $rows[] = [$v['reference'], $v['plate'], $v['make'], $v['model'], $v['year'], ['diesel' => 'Diesel', 'petrol' => 'Essence', 'hybrid' => 'Hybride', 'electric' => 'Électrique'][$v['fuel']] ?? '', ['manual' => 'Manuelle', 'automatic' => 'Automatique'][$v['transmission']] ?? '', $v['current_odometer'], $v['current_odometer_date'] ? date('d/m/Y', strtotime($v['current_odometer_date'])) : '', $price];
                } else {
                    $rows[] = [$v['reference'], $v['first_name'], $v['last_name'], $v['phone'], $v['city']];
                }
            }
            $page++;
        } while ($page <= $listing['last_page']);
        $headers = $kind === 'vehicles' ? ['Référence', 'Immatriculation', 'Marque', 'Modèle', 'Année', 'Carburant', 'Boîte', 'Kilométrage', 'Date du relevé', 'Location / jour (MAD)'] : ['Référence', 'Prénom', 'Nom', 'Téléphone', 'Ville'];

        return response()->download($excel->write($headers, $rows), $kind.'-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend(true);
    }

    private function exportMaintenance(Request $request, ExcelExchange $excel)
    {
        $request->merge(['tab' => $request->input('tab', 'files')]);
        $files = $request->input('tab') === 'files';
        $rows = [];
        $page = 1;
        $labels = ['file' => 'Dossier', 'pricing' => 'Prix à confirmer', 'readiness' => 'Retour à contrôler', 'garage' => 'Au garage', 'deferred' => 'Travail reporté', 'payments' => 'Paiement restant', 'credits' => 'Avance à vérifier', 'reminder' => 'Entretien à prévoir', 'cancelled' => 'Annulé', 'price_pending' => 'Prix à confirmer', 'unready' => 'À contrôler', 'overdue' => 'En retard', 'due' => 'Échéance atteinte', 'urgent' => 'Dans les 7 jours', 'soon' => 'À prévoir', 'scheduled' => 'Planifié', 'credit' => 'Avance à vérifier', 'done' => 'Terminé', 'pending' => 'À faire', 'progress' => 'En cours'];
        $money = fn ($v) => $v === null ? '' : intdiv((int) $v, 100).','.str_pad((string) ((int) $v % 100), 2, '0', STR_PAD_LEFT);
        do {
            $request->merge(['page' => $page]);
            $listing = app(MaintenanceOverviewController::class)->index($request, app(VehicleMaintenance::class))->getData(true);
            if ($listing['total'] > 10000) {
                throw ValidationException::withMessages(['export' => 'Plus de 10 000 lignes. Affinez les filtres.']);
            }
            $financial = $listing['can_manage_finance'];
            foreach ($listing['data'] as $v) {
                $row = [$v['entry_id'] === null ? '' : 'Dossier #'.$v['entry_id'], $v['vehicle']['plate'], $v['vehicle']['make'].' '.$v['vehicle']['model'], $v['title'], empty($v['date']) ? '' : date('d/m/Y', strtotime($v['date'])), $v['km'] ?? '', $labels[$v['category']] ?? $v['category']];
                if ($files) {
                    $state = $v['cancelled'] ? 'Dossier annulé' : ($v['garage_open'] ? 'Voiture au garage' : (! empty($v['readiness_pending']) ? 'Revenue · à contrôler' : ($labels[$v['work_state']] ?? $v['work_state'])));
                    array_push($row, implode(' · ', $v['work_titles']), $v['garage'] ?? '', $state);
                    if ($financial) {
                        array_push($row, ($v['unknown_count'] ?? 0) && $v['cost_centimes'] === 0 ? 'Non défini' : ($v['cost_final'] ? 'Confirmé' : 'Provisoire'), ($v['unknown_count'] ?? 0) && $v['cost_centimes'] === 0 ? '' : $money($v['cost_centimes']), $money($v['paid_centimes']), $money($v['balance_centimes']), $money($v['credit_centimes']));
                    }
                } else {
                    array_push($row, $labels[$v['state']] ?? $v['state'], $v['reason'] ?? '');
                    if ($financial) {
                        array_push($row, $money($v['amount_centimes'] ?? null), isset($v['cost_final']) ? ($v['cost_final'] ? 'Confirmé' : 'Provisoire') : '');
                    }
                }
                $rows[] = $row;
            }
            $page++;
        } while ($page <= $listing['last_page']);
        $headers = ['Dossier', 'Immatriculation', 'Voiture', 'Intervention / action', 'Date', 'Kilométrage', 'Catégorie'];
        if ($files) {
            array_push($headers, 'Travaux', 'Garage / prestataire', 'État');
            if ($financial) {
                array_push($headers, 'État du coût', 'Coût (MAD)', 'Net payé (MAD)', 'Reste à payer (MAD)', 'Excédent (MAD)');
            }
        } else {
            array_push($headers, 'État', 'Observation');
            if ($financial) {
                array_push($headers, 'Montant (MAD)', 'État du montant');
            }
        }

        return response()->download($excel->write($headers, $rows), 'maintenance-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend(true);
    }

    public function preview(Request $request, string $kind, ExcelExchange $excel)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:xlsx']]);
        $rows = $excel->validateRows($excel->read($request->file('file')->getRealPath(), $kind), $kind, $request);
        $invalid = count(array_filter($rows, fn ($r) => count($r['errors']) > 0));
        $token = null;
        if (! $invalid) {
            $token = Crypt::encryptString(json_encode(['company' => Tenant::id(), 'user' => $request->user()->id, 'kind' => $kind, 'expires' => now()->addMinutes(30)->timestamp, 'hash' => hash_file('sha256', $request->file('file')->getRealPath()), 'rows' => $rows]));
        }

        return response()->json(['columns' => $excel->columns($kind), 'rows' => array_map(fn ($r) => ['line' => $r['line'], 'data' => array_intersect_key($r['data'], $excel->columns($kind)), 'errors' => $r['errors']], $rows), 'invalid' => $invalid, 'token' => $token]);
    }

    public function confirm(Request $request, string $kind, ExcelExchange $excel)
    {
        $request->validate(['token' => ['required', 'string', 'max:2000000']]);
        try {
            $batch = json_decode(Crypt::decryptString($request->input('token')), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['token' => 'Aperçu invalide. Analysez le fichier à nouveau.']);
        }
        abort_unless($batch['company'] === Tenant::id() && $batch['user'] === $request->user()->id && $batch['kind'] === $kind, 403);
        if ($batch['expires'] < now()->timestamp) {
            throw ValidationException::withMessages(['token' => 'Aperçu expiré. Analysez le fichier à nouveau.']);
        }

        return DB::transaction(function () use ($request, $kind, $excel, $batch) {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $rows = $excel->validateRows($batch['rows'], $kind, $request, true);
            if (array_filter($rows, fn ($r) => count($r['errors']) > 0)) {
                return response()->json(['message' => 'Des données ont changé depuis la prévisualisation. Aucun fichier ajouté. Analysez le fichier à nouveau.', 'rows' => $rows], 409);
            }
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
            if ($kind === 'maintenance') {
                $import = app(MaintenanceExcelImport::class);
                $ids = [];
                foreach ($rows as $row) {
                    $ids[] = $import->save($row['data'], $actor->id, $batch['hash'], $row['line']);
                }

                return response()->json(['count' => count($ids), 'ids' => $ids, 'message' => count($ids).' intervention(s) importée(s).']);
            }
            $model = $kind === 'vehicles' ? Vehicle::class : Customer::class;
            $save = app($kind === 'vehicles' ? SaveVehicle::class : SaveCustomer::class);
            $ids = [];
            foreach ($rows as $row) {
                $existing = $model::where('creation_key', $row['data']['creation_key'])->exists();
                $record = $save->handle($row['data'], $request->user()->id);
                $ids[] = $record->id;
                if (! $existing) {
                    DB::table($kind === 'vehicles' ? 'vehicle_events' : 'customer_events')->insert([
                        'company_id' => Tenant::id(), $kind === 'vehicles' ? 'vehicle_id' : 'customer_id' => $record->id,
                        'actor_membership_id' => $actor->id, 'action' => 'excel_imported', 'before' => null,
                        'after' => json_encode(['source_sha256' => $batch['hash'], 'line' => $row['line'], 'reference' => $record->reference]), 'created_at' => now(),
                    ]);
                }
            }

            return response()->json(['count' => count($ids), 'ids' => $ids, 'message' => count($ids).' fichier(s) enregistré(s).']);
        });
    }
}
