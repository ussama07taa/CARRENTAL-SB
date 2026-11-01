<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveFineDocumentRequest;
use App\Support\Fines\FineFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FineController extends Controller
{
    public function index(Request $r, FineFiles $s)
    {
        $actor = $s->actor($r->user()->id);
        $v = $r->validate(['status' => ['nullable', Rule::in(['all', 'open', 'disputed', 'closed'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = $s->query();
        if (($v['status'] ?? 'all') !== 'all') {
            $q->whereRaw("COALESCE((SELECT status FROM traffic_fine_events e WHERE e.company_id=traffic_fines.company_id AND e.fine_id=traffic_fines.id AND e.action='followup' ORDER BY e.id DESC LIMIT 1),'open') = ?", [$v['status']]);
        }
        $p = $q->orderByDesc('id')->paginate(10);

        return response()->json(['data' => collect($p->items())->map(fn ($f) => $s->detail($f->id, $actor)), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function show(Request $r, FineFiles $s, int $fine)
    {
        return response()->json(['data' => $s->detail($fine, $s->actor($r->user()->id))]);
    }

    public function store(Request $r, FineFiles $s)
    {
        $actor = $s->actor($r->user()->id);
        $v = $r->validate(['vehicle_id' => ['required', 'integer', 'min:1'], 'occurred_local' => ['required', 'date_format:Y-m-d\TH:i'], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'authority' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'amount_centimes' => ['prohibited'], 'reservation_id' => ['prohibited']]);
        $id = $s->create($v, $actor);

        return response()->json(['id' => $id, 'data' => $s->detail($id, $actor)], 201);
    }

    public function event(Request $r, FineFiles $s, int $fine)
    {
        $actor = $s->actor($r->user()->id);
        if (in_array($r->input('action'), ['expense', 'declaration', 'client_direct'], true)) {
            abort_unless($actor->is_primary_responsible, 403);
        }
        $v = $r->validate(['action' => ['required', Rule::in(['followup', 'rental_review', 'expense', 'declaration', 'client_direct'])], 'expected_revision' => ['required', 'integer', 'min:0'], 'note' => ['required', 'string', 'max:1000'], 'status' => ['required_if:action,followup', Rule::in(['open', 'disputed', 'closed'])], 'next_on' => ['nullable', 'date_format:Y-m-d'], 'pickup_id' => ['required_if:action,rental_review', 'integer', 'min:1'], 'expense_id' => ['nullable', 'integer', 'min:1'], 'amount_centimes' => [Rule::requiredIf($r->input('action') === 'expense' && ! $r->filled('expense_id')), 'integer', 'min:1', 'max:1000000000'], 'due_on' => ['nullable', 'date_format:Y-m-d'],
            'handling_stage' => ['required_if:action,declaration', Rule::in(['prepared', 'submitted'])],
            'person_name' => ['required_if:action,declaration,client_direct', 'string', 'max:150'],
            'effective_on' => ['required_if:action,declaration,client_direct', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()],
            'recipient' => ['nullable', 'string', 'max:150'], 'external_reference' => ['nullable', 'string', 'max:100'],
            'evidence_document_id' => ['nullable', 'integer', 'min:1'],
            'confirmed' => ['exclude_unless:action,declaration,client_direct', Rule::requiredIf(in_array($r->input('action'), ['declaration', 'client_direct'], true)), 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $s->event($fine, $v, $actor);

        return response()->json(['data' => $s->detail($fine, $actor)], 201);
    }

    public function document(SaveFineDocumentRequest $r, FineFiles $s, int $fine)
    {
        $a = $s->actor($r->user()->id);
        $s->document($fine, $r->file('file'), $r->validated('request_key'), $a);

        return response()->json(['data' => $s->detail($fine, $a)], 201);
    }

    public function content(Request $r, FineFiles $s, int $fine, int $document)
    {
        $s->detail($fine, $s->actor($r->user()->id));
        $d = $s->query('traffic_fine_documents')->where('fine_id', $fine)->where('id', $document)->firstOrFail();
        $disk = Storage::disk('fine_documents');
        abort_unless($disk->exists($d->path), 404);
        $bytes = $disk->get($d->path);
        abort_unless(hash_equals($d->checksum, hash('sha256', $bytes)), 409);

        return response($bytes)->header('Content-Type', $d->mime)->header('Content-Disposition', 'attachment; filename="avis-'.$d->id.'.'.($d->mime === 'application/pdf' ? 'pdf' : 'image').'"')->header('X-Content-Type-Options', 'nosniff');
    }
}
