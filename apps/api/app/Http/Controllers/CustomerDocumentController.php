<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerDocumentRequest;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDocumentController extends Controller
{
    public function store(StoreCustomerDocumentRequest $request, int $customer): JsonResponse
    {
        Customer::findOrFail($customer);
        $input = $request->validated();
        $file = $request->file('image');
        $hash = hash_file('sha256', $file->getRealPath());
        $disk = 'customer_documents';
        $storedPath = null;
        try {
            $result = DB::transaction(function () use ($request, $customer, $input, $file, $hash, $disk, &$storedPath): CustomerDocument|JsonResponse {
                // Match the customer-edit lock order and serialize upload-key retries.
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                Customer::whereKey($customer)->lockForUpdate()->firstOrFail();
                $existing = CustomerDocument::where('upload_key', $input['upload_key'])->first();
                if ($existing) {
                    if ((int) $existing->customer_id !== $customer || $existing->slot !== $input['slot'] || ! hash_equals($existing->sha256, $hash)) {
                        return response()->json(['code' => 'UPLOAD_KEY_USED', 'message' => 'Cette demande a déjà été utilisée. Choisissez à nouveau l’image.'], 409);
                    }

                    return $existing;
                }
                $previous = CustomerDocument::where('customer_id', $customer)->where('slot', $input['slot'])->latest('version')->first();
                if (($previous?->id ?? null) !== (isset($input['previous_id']) ? (int) $input['previous_id'] : null)) {
                    return response()->json(['code' => 'DOCUMENT_CHANGED', 'message' => 'Cette photo a été remplacée ailleurs. Fermez et rouvrez le fichier pour voir la dernière version avant de la remplacer.'], 409);
                }
                $actor = CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->firstOrFail();
                $path = Tenant::id().'/'.$customer.'/'.Str::uuid().'.'.$file->extension();
                if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                    throw new \RuntimeException('Private document storage failed.');
                }
                $storedPath = $path;
                $document = CustomerDocument::create([
                    'customer_id' => $customer, 'actor_membership_id' => $actor->id,
                    'slot' => $input['slot'], 'version' => ($previous?->version ?? 0) + 1,
                    'upload_key' => $input['upload_key'], 'disk' => $disk, 'path' => $path,
                    'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash,
                    'created_at' => now(),
                ]);
                DB::table('customer_events')->insert([
                    'company_id' => Tenant::id(), 'customer_id' => $customer, 'actor_membership_id' => $actor->id,
                    'action' => 'document_added', 'before' => null,
                    'after' => json_encode(['document_id' => $document->id, 'slot' => $document->slot, 'version' => $document->version]),
                    'created_at' => now(),
                ]);

                return $document;
            });
        } catch (\Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk($disk)->delete($storedPath);
            }
            throw $error;
        }

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $result], 201);
    }

    public function content(Request $request, int $customer, int $document): StreamedResponse
    {
        abort_unless(CompanyMembership::where('user_id', $request->user()->id)->where('is_primary_responsible', true)->exists(), 403);
        Customer::findOrFail($customer);
        $record = CustomerDocument::where('customer_id', $customer)->findOrFail($document);
        $storage = Storage::disk($record->disk);
        abort_unless($storage->exists($record->path), 404, 'Image indisponible.');

        return $storage->response($record->path, 'document.'.(match ($record->mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        }), [
            'Content-Type' => $record->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
