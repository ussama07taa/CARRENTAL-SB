<?php
namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Support\Reservations\AgencyDocumentProfile;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgencyProfileController extends Controller
{
 private function actor(Request $request): CompanyMembership {
  $actor=CompanyMembership::where('user_id',$request->user()->id)->sole();
  abort_unless($actor->is_primary_responsible,403,'Les paramètres de l’agence sont réservés au gérant.');
  abort_unless(Schema::hasTable('agency_profiles'),503,'Le profil de l’agence doit être activé par votre installateur.');
  return $actor;
 }
 private function state(): array {
  $row=DB::table('agency_profiles')->where('company_id',Tenant::id())->first();
  $profile=app(AgencyDocumentProfile::class)->current();
  $details=array_intersect_key($profile,array_flip(['name','address','phone','email','ice','rc','if','tp','capital']));
  return ['details'=>$details,'logo_base64'=>$row?->logo_base64,'logo_mime'=>$row?->logo_mime];
 }
 private function revision(array $state): string { ksort($state['details']);return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)); }
 private function payload(): array {
  $state=$this->state();
  $history=DB::table('company_setup_events as e')
   ->join('company_memberships as m',function($j){$j->on('m.id','=','e.responsible_membership_id')->on('m.company_id','=','e.company_id');})
   ->join('users as u','u.id','=','m.user_id')->where('e.company_id',Tenant::id())
   ->where('e.source','agency_profile_updated')->orderByDesc('e.id')->limit(10)->get(['e.id','e.created_at','e.operator_name as actor']);
  return ['details'=>$state['details'],'revision'=>$this->revision($state),
   'logo'=>$state['logo_base64']?'data:'.$state['logo_mime'].';base64,'.$state['logo_base64']:null,'history'=>$history];
 }
 public function show(Request $request) {
  $this->actor($request);return response()->json($this->payload());
 }
 public function update(Request $request) {
  $actor=$this->actor($request);
  $input=$request->validate([
   'expected_revision'=>'required|string|size:64','company_id'=>'prohibited',
   'name'=>'required|string|max:255','address'=>'required|string|max:500','phone'=>'required|string|max:40',
   'email'=>'nullable|email:rfc|max:255','ice'=>'nullable|regex:/^[0-9]{15}$/',
   'rc'=>'nullable|string|max:100','if'=>'nullable|string|max:100','tp'=>'nullable|string|max:100','capital'=>'nullable|string|max:100',
   'remove_logo'=>'sometimes|boolean','logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:1024',
  ],['ice.regex'=>'L’ICE doit contenir 15 chiffres.']);
  abort_if($request->boolean('remove_logo') && $request->hasFile('logo'),422,'Choisissez un nouveau logo ou sa suppression, pas les deux.');
  return DB::transaction(function() use($request,$input,$actor){
   DB::table('companies')->where('id',Tenant::id())->lockForUpdate()->firstOrFail();
   $before=$this->state();
   abort_unless(hash_equals($this->revision($before),$input['expected_revision']),409,'Les informations ont changé dans une autre fenêtre. Rechargez le profil avant d’enregistrer.');
   $details=[];
   foreach(['name','address','phone','email','ice','rc','if','tp','capital'] as $key) {
    $value=trim((string)($input[$key]??''));if($value!=='')$details[$key]=$value;
   }
   $after=['details'=>$details,'logo_base64'=>$before['logo_base64'],'logo_mime'=>$before['logo_mime']];
   if($request->boolean('remove_logo')){$after['logo_base64']=null;$after['logo_mime']=null;}
   if($logo=$request->file('logo')){$after['logo_base64']=base64_encode(file_get_contents($logo->getRealPath()));$after['logo_mime']=$logo->getMimeType();}
   if($this->revision($after)!==$this->revision($before)){
    $existing=DB::table('agency_profiles')->where('company_id',Tenant::id())->exists();
    $values=['details'=>json_encode($details,JSON_THROW_ON_ERROR),'logo_base64'=>$after['logo_base64'],'logo_mime'=>$after['logo_mime'],'updated_at'=>now()];
    if($existing)DB::table('agency_profiles')->where('company_id',Tenant::id())->update($values);
    else DB::table('agency_profiles')->insert(['company_id'=>Tenant::id(),'created_at'=>now(),...$values]);
    DB::table('companies')->where('id',Tenant::id())->update(['name'=>$details['name'],'updated_at'=>now()]);
    DB::table('company_setup_events')->insert(['company_id'=>Tenant::id(),'responsible_membership_id'=>$actor->id,
     'source'=>'agency_profile_updated','operator_name'=>$request->user()->name,'created_at'=>now()]);
   }
   return response()->json($this->payload());
  });
 }
}
