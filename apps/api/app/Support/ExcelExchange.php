<?php

namespace App\Support;

use App\Http\Requests\SaveCustomerRequest;
use App\Http\Requests\SaveVehicleRequest;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Support\Customers\CustomerFields;
use App\Support\Vehicles\MaintenanceExcelImport;
use App\Support\Vehicles\VehicleFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class ExcelExchange
{
    public function columns(string $kind): array
    {
        if ($kind === 'maintenance') {
            return MaintenanceExcelImport::COLUMNS;
        }

        return $kind === 'vehicles' ? [
            'plate' => 'Immatriculation *', 'make' => 'Marque *', 'model' => 'Modèle *', 'year' => 'Année',
            'fuel' => 'Carburant', 'transmission' => 'Boîte', 'color' => 'Couleur', 'vin' => 'Châssis',
            'initial_odometer' => 'Kilométrage initial', 'initial_odometer_date' => 'Date du relevé (jj/mm/aaaa)',
        ] : [
            'first_name' => 'Prénom *', 'last_name' => 'Nom *', 'phone' => 'Téléphone *', 'email' => 'E-mail',
            'city' => 'Ville', 'address' => 'Adresse', 'language' => 'Langue',
            'identity_type' => 'Type de pièce', 'identity_number' => 'Numéro de pièce',
            'licence_number' => 'Numéro de permis', 'licence_expires_on' => 'Validité du permis (jj/mm/aaaa)',
        ];
    }

    public function write(array $headers, iterable $rows, string $sheet = 'Données', ?string $guide = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bensbih-xlsx-');
        try {
            $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 26));
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName($sheet);
            $style = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '004FFF');
            $writer->addRow(new Row(array_map(fn ($v) => new StringCell((string) $v, $style), $headers)));
            foreach ($rows as $row) {
                // Explicit strings preserve phones/identifiers and never create formulas.
                $writer->addRow(new Row(array_map(fn ($v) => new StringCell((string) ($v ?? '')), array_values($row))));
            }
            if ($guide !== null) {
                $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
                foreach (explode("\n", $guide) as $line) {
                    $writer->addRow(new Row([new StringCell($line)]));
                }
            }
            $writer->close();

            return $path;
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    public function read(string $path, string $kind): array
    {
        // Bound both compressed and expanded sizes before the XML reader opens the file.
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Fichier Excel .xlsx invalide.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 200) {
                $this->fail('Classeur trop complexe. Utilisez le modèle fourni.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry['size'];
                if ($total > 20 * 1024 * 1024) {
                    $this->fail('Classeur trop volumineux. Maximum 500 lignes.');
                }
                if (str_ends_with($entry['name'], '.xml')) {
                    $xml = $zip->getFromIndex($i);
                    if (preg_match('/<!DOCTYPE|<!ENTITY|<([a-zA-Z0-9_]+:)?f(?:\s|>)/i', $xml)) {
                        $this->fail('Les formules ne sont pas acceptées. Collez les valeurs uniquement.');
                    }
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Reader;
        try {
            $reader->open($path);
            $rows = [];
            $expected = array_values($this->columns($kind));
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() === 'Instructions') {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = $row->toArray();
                    if ($number === 1) {
                        if ($values !== $expected) {
                            $this->fail('Colonnes différentes du modèle. Téléchargez le modèle et conservez ses en-têtes dans le même ordre.');
                        }

                        continue;
                    }
                    if (count($values) > count($expected)) {
                        $this->fail('Colonnes supplémentaires non autorisées.');
                    }
                    if (! array_filter($values, fn ($v) => $v !== null && $v !== '')) {
                        continue;
                    }
                    if (count($rows) >= 500) {
                        $this->fail('Maximum 500 lignes par import.');
                    }
                    $values = array_pad($values, count($expected), null);
                    foreach ($values as &$value) {
                        if ($value instanceof \DateTimeInterface) {
                            $value = $value->format('d/m/Y');
                        } elseif (! is_scalar($value) && $value !== null) {
                            $this->fail('Une cellule contient une valeur non prise en charge.');
                        }
                        $value = $value === null ? null : trim((string) $value);
                        if (strlen($value ?? '') > 2000) {
                            $this->fail('Une cellule contient trop de texte.');
                        }
                        if ($value === '') {
                            $value = null;
                        }
                    }
                    unset($value);
                    $rows[] = ['line' => $number, 'data' => array_combine(array_keys($this->columns($kind)), $values)];
                }
                break;
            }
            if (! $rows) {
                $this->fail('Le fichier ne contient aucune ligne à importer.');
            }

            return $rows;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail('Impossible de lire ce fichier .xlsx. Utilisez le modèle fourni.');
        } finally {
            $reader->close();
        }
    }

    public function validateRows(array $rows, string $kind, Request $request, bool $retry = false): array
    {
        if ($kind === 'maintenance') {
            return app(MaintenanceExcelImport::class)->validateRows($rows, $retry);
        }
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $errors = [];
            $data['creation_key'] ??= (string) Str::uuid();
            if ($kind === 'vehicles') {
                $data['plate'] = mb_strtoupper($data['plate'] ?? '');
                $data['vin'] = empty($data['vin']) ? null : VehicleFields::identifier($data['vin']);
                $data['equipment'] = [];
                $data['fuel'] = ['essence' => 'petrol', 'diesel' => 'diesel', 'hybride' => 'hybrid', 'électrique' => 'electric'][mb_strtolower($data['fuel'] ?? '')] ?? $data['fuel'];
                $data['transmission'] = ['manuelle' => 'manual', 'automatique' => 'automatic'][mb_strtolower($data['transmission'] ?? '')] ?? $data['transmission'];
                $form = SaveVehicleRequest::create('/', 'POST');
                $model = Vehicle::class;
                $identifiers = ['plate' => VehicleFields::identifier($data['plate']), 'vin' => $data['vin']];
            } else {
                $data['phone'] = CustomerFields::phone($data['phone']);
                $data['identity_number'] = CustomerFields::identity($data['identity_number']);
                $data['language'] ??= 'fr';
                $form = SaveCustomerRequest::create('/', 'POST');
                $model = Customer::class;
                $identifiers = ['phone' => $data['phone'], 'name' => mb_strtolower(($data['first_name'] ?? '').'|'.($data['last_name'] ?? '')), 'identity' => empty($data['identity_number']) ? null : ($data['identity_type'] ?? '').'|'.$data['identity_number']];
            }
            foreach (['initial_odometer_date', 'licence_expires_on'] as $field) {
                if (! empty($data[$field]) && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data[$field], $m)) {
                    $data[$field] = "$m[3]-$m[2]-$m[1]";
                }
            }
            $validator = Validator::make($data, $form->rules(), $form->messages(), $this->columns($kind));
            $errors = $validator->errors()->all();
            foreach ($identifiers as $field => $value) {
                if (! $value) {
                    continue;
                }
                if (isset($seen[$field][$value])) {
                    $errors[] = 'Doublon dans le fichier (ligne '.$seen[$field][$value].').';
                }
                $seen[$field][$value] = $row['line'];
            }
            $own = $retry ? $model::where('creation_key', $data['creation_key'])->first() : null;
            if (! $errors && ! $own) {
                if ($kind === 'vehicles') {
                    $duplicate = Vehicle::where('plate_key', $identifiers['plate'])->when($data['vin'], fn ($q) => $q->orWhere('vin', $data['vin']))->exists();
                } else {
                    $duplicate = Customer::where(function ($q) use ($data) {
                        $q->where('phone', $data['phone'])->orWhere(function ($q) use ($data) {
                            $q->whereRaw('lower(first_name)=lower(?)', [$data['first_name']])->whereRaw('lower(last_name)=lower(?)', [$data['last_name']]);
                        });
                        if (! empty($data['identity_number'])) {
                            $q->orWhere(fn ($q) => $q->where('identity_type', $data['identity_type'])->where('identity_number', $data['identity_number']));
                        }
                    })->exists();
                }
                if ($duplicate) {
                    $errors[] = 'Un fichier correspondant existe déjà dans votre agence. Aucun remplacement automatique.';
                }
            }
            $result[] = ['line' => $row['line'], 'data' => $data, 'errors' => array_values(array_unique($errors))];
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
