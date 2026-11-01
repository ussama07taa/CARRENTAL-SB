<?php
namespace App\Http\Controllers;

use App\Actions\Companies\CreateCompanyWithResponsible;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InstallationController extends Controller
{
 private function emptyInstallation(): bool {
  return ! DB::table('companies')->exists() && ! DB::table('users')->exists();
 }
 public function status() {
  return response()->json(['available' => strlen((string) config('installation.token_hash')) === 64
   && Schema::hasTable('agency_profiles') && $this->emptyInstallation()]);
 }
 public function store(Request $request, CreateCompanyWithResponsible $create) {
  abort_unless($this->emptyInstallation(), 409, 'Cette installation est déjà configurée. Connectez-vous à votre agence.');
  $hash = (string) config('installation.token_hash');
  abort_unless(strlen($hash) === 64, 403, 'L’installation doit être activée par votre installateur.');
  if (! hash_equals($hash, hash('sha256', (string) $request->input('installation_code')))) {
   throw ValidationException::withMessages(['installation_code' => 'Code d’installation incorrect.']);
  }
  $data = $request->validate([
   'company_name'=>'required|string|max:255', 'responsible_name'=>'required|string|max:255',
   'email'=>'required|email:rfc|max:255', 'password'=>'required|string|min:12|max:72|confirmed',
   'address'=>'required|string|max:500', 'phone'=>'required|string|max:40',
   'agency_email'=>'nullable|email:rfc|max:255',
   'ice'=>'nullable|regex:/^[0-9]{15}$/', 'rc'=>'nullable|string|max:100',
   'if'=>'nullable|string|max:100', 'tp'=>'nullable|string|max:100', 'capital'=>'nullable|string|max:100',
   'logo'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:1024',
  ], ['password.confirmed'=>'Les mots de passe ne correspondent pas.', 'ice.regex'=>'L’ICE doit contenir 15 chiffres.']);
  DB::transaction(function () use ($data,$request,$create) {
   // Same lock as the private CLI bootstrap: only one first owner can win.
   DB::statement('LOCK TABLE companies IN SHARE ROW EXCLUSIVE MODE');
   abort_unless($this->emptyInstallation(), 409, 'Cette installation est déjà configurée.');
   $company = $create->handle($data['company_name'],$data['responsible_name'],$data['email'],$data['password'],'installation_web');
   $details = array_intersect_key($data,array_flip(['address','phone','ice','rc','if','tp','capital']));
   $details['name']=$data['company_name']; $details['email']=$data['agency_email'] ?? '';
   $logo=$request->file('logo');
   DB::table('agency_profiles')->insert([
    'company_id'=>$company->id,'details'=>json_encode($details,JSON_THROW_ON_ERROR),
    'logo_base64'=>$logo ? base64_encode(file_get_contents($logo->getRealPath())) : null,
    'logo_mime'=>$logo?->getMimeType(),'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('company_settings')->insert(['company_id'=>$company->id,'preparation_minutes'=>120]);
  });
  return response()->json(['created'=>true],201);
 }
 public function branding() {
  $company=DB::table('companies')->orderBy('id')->first();
  $profile=$company && Schema::hasTable('agency_profiles') ? DB::table('agency_profiles')->where('company_id',$company->id)->first() : null;
  return response()->json(['name'=>$company?->name,'custom'=>(bool)$profile,'has_logo'=>(bool)($profile?->logo_base64),'logo_revision'=>$profile?->logo_base64 ? hash('sha256',$profile->logo_base64) : null]);
 }
 public function logo() {
  $company=DB::table('companies')->orderBy('id')->first();
  abort_unless($company && Schema::hasTable('agency_profiles'),404);
  $profile=DB::table('agency_profiles')->where('company_id',$company->id)->first();
  abort_unless($profile?->logo_base64,404);
  return response(base64_decode($profile->logo_base64),200,['Content-Type'=>$profile->logo_mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store']);
 }
}
