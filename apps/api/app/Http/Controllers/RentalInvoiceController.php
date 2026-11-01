<?php
namespace App\Http\Controllers;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\InvoiceSnapshot;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class RentalInvoiceController extends Controller {
 private function actor(Request $r): CompanyMembership {
  $a=CompanyMembership::where('user_id',$r->user()->id)->sole();
  abort_unless($a->is_primary_responsible,403,'La facturation est réservée au gérant.');return $a;
 }
 private function query(){return DB::table('rental_invoices')->where('company_id',Tenant::id());}
 private function metadata($row): array {return ['id'=>$row->id,'number'=>$row->number,'created_at'=>$row->created_at,'sha256'=>$row->sha256];}
 public function show(Request $request,int $reservation){
  $this->actor($request);Reservation::findOrFail($reservation);
  $row=Schema::hasTable('rental_invoices')?$this->query()->where('reservation_id',$reservation)->first():null;
  return response()->json(['invoice'=>$row?$this->metadata($row):null,'enabled'=>(bool)config('rental_contracts.real_documents_enabled')&&Schema::hasTable('rental_invoices')]);
 }
 public function issue(Request $request,int $reservation,InvoiceSnapshot $snapshots,ContractPdf $pdf){
  $actor=$this->actor($request);
  abort_unless(config('rental_contracts.real_documents_enabled')&&Schema::hasTable('rental_invoices'),422,'L’émission des factures attend son activation.');
  $input=$request->validate(['request_key'=>'required|uuid','review_hash'=>'required|string|size:64','reviewed'=>'required|accepted']);
  $requestHash=hash('sha256',json_encode([$reservation,$input['review_hash']]));
  $path=null;
  try{
   return DB::transaction(function()use($reservation,$actor,$input,$snapshots,$pdf,$requestHash,&$path){
    DB::table('companies')->where('id',Tenant::id())->lockForUpdate()->firstOrFail();
    $r=Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
    $replay=$this->query()->where('request_key',$input['request_key'])->first();
    if($replay){abort_unless(hash_equals($replay->request_hash,$requestHash),409,'Ce même envoi correspond à d’autres informations.');return response()->json(['invoice'=>$this->metadata($replay),'replayed'=>true]);}
    $existing=$this->query()->where('reservation_id',$reservation)->first();
    if($existing)return response()->json(['invoice'=>$this->metadata($existing),'replayed'=>true]);
    abort_unless(in_array($r->status,['confirmee','en_location','retournee']),422,'Confirmez la réservation avant d’émettre la facture.');
    abort_if(DB::table('rental_pickups')->where('company_id',Tenant::id())->where('reservation_id',$reservation)->whereRaw("snapshot->>'demo_simulation' = 'true'")->exists(),422,'Un dossier simulé ne peut pas recevoir une facture réelle.');
    $s=$snapshots->make($reservation);
    abort_unless(hash_equals($snapshots->fingerprint($s),$input['review_hash']),409,'Le dossier a changé. Prévisualisez de nouveau la facture.');
    abort_unless(isset($s['tax']) && (int)$s['tax']['gross_centimes']===$s['total_centimes'],422,'Enregistrez le mode et le taux de TVA dans le prix convenu avant émission.');
    foreach(['name','address','ice','rc','if'] as $key)abort_if(empty($s['agency'][$key]),422,'Complétez les informations de facturation dans Paramètres → Mon agence : '.strtoupper($key).'.');
    $year=(int)now('Africa/Casablanca')->format('Y');
    $counter=DB::selectOne("INSERT INTO company_counters (company_id,kind,year,last_number) VALUES (?, 'invoice', ?, 1) ON CONFLICT (company_id,kind,year) DO UPDATE SET last_number=company_counters.last_number+1 RETURNING last_number",[Tenant::id(),$year]);
    $number='FAC-'.$year.'-'.str_pad((string)$counter->last_number,4,'0',STR_PAD_LEFT);
    $s['number']=$number;$s['issued']=true;
    $bytes=$pdf->renderHtml(view('invoices.draft',['s'=>$s])->render());
    abort_unless(str_starts_with($bytes,'%PDF-'),500,'La génération du PDF a échoué.');
    $path=Tenant::id().'/'.Str::uuid().'.pdf';
    Storage::disk('rental_invoices')->put($path,$bytes);
    $id=$this->query()->insertGetId(['company_id'=>Tenant::id(),'reservation_id'=>$reservation,'actor_membership_id'=>$actor->id,'number'=>$number,
     'snapshot'=>json_encode($s,JSON_THROW_ON_ERROR),'fingerprint'=>$snapshots->fingerprint($s),'path'=>$path,'sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'request_key'=>$input['request_key'],'request_hash'=>$requestHash,'created_at'=>now()]);
    app(RentalContract::class)->event($reservation,$actor->id,'invoice_issued',['invoice_id'=>$id,'number'=>$number]);
    return response()->json(['invoice'=>$this->metadata($this->query()->where('id',$id)->first()),'replayed'=>false],201);
   });
  }catch(\Throwable $e){if($path){try{if(!$this->query()->where('path',$path)->exists())Storage::disk('rental_invoices')->delete($path);}catch(\Throwable){/* Retain the private file when commit status cannot be checked. */}}throw $e;}
 }
 public function content(Request $request,int $reservation){
  $this->actor($request);Reservation::findOrFail($reservation);
  abort_unless(Schema::hasTable('rental_invoices'),404);
  $row=$this->query()->where('reservation_id',$reservation)->firstOrFail();
  abort_unless(Storage::disk('rental_invoices')->exists($row->path),409,'L’original est indisponible. Restaurez-le depuis la sauvegarde ; aucun nouveau PDF ne sera généré.');
  $bytes=Storage::disk('rental_invoices')->get($row->path);
  abort_unless(hash_equals($row->sha256,hash('sha256',$bytes)),409,'L’intégrité du fichier original doit être vérifiée.');
  return response($bytes)->header('Content-Type','application/pdf')->header('Content-Disposition','attachment; filename="'.$row->number.'.pdf"');
 }
}
