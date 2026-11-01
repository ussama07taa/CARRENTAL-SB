<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class EmployeeController extends Controller {
 private function actor(Request $r): CompanyMembership {
  $actor=CompanyMembership::where('user_id',$r->user()->id)->sole();
  abort_unless($actor->is_primary_responsible && $actor->is_active,403,'La gestion des utilisateurs est réservée au responsable.');return $actor;
 }
 public function index(Request $r) {
  $this->actor($r);
  $members=CompanyMembership::with('user')->orderByDesc('is_primary_responsible')->orderBy('id')->get();
  return response()->json(['data'=>$members->map(fn($m)=>['id'=>$m->id,'name'=>$m->user->name,'email'=>$m->user->email,'role'=>$m->is_primary_responsible?'responsable':'employe','is_self'=>$m->user_id===$r->user()->id,'active'=>(bool)$m->is_active,'can_toggle'=>!($m->is_primary_responsible && $m->is_active && $members->where('is_primary_responsible',true)->where('is_active',true)->count()<=1)]),'history'=>DB::table('employee_access_events as e')->where('e.company_id',Tenant::id())->join('company_memberships as m',fn($j)=>$j->on('m.company_id','=','e.company_id')->on('m.id','=','e.subject_membership_id'))->join('users as u','u.id','=','m.user_id')->orderByDesc('e.id')->limit(20)->get(['e.action','e.reason','e.created_at','u.name'])]);
 }
 public function store(Request $r) {
  $actor=$this->actor($r);
  $r->merge(['email'=>mb_strtolower(trim((string)$r->input('email')))]);
  $v=$r->validate(['name'=>'required|string|max:150','email'=>'required|email|max:254','password'=>'required|string|min:12|max:128|confirmed','role'=>'sometimes|in:employe,responsable','company_id'=>'prohibited','is_primary_responsible'=>'prohibited']);
  try {$id=DB::transaction(function() use($v,$actor){
   Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
   $actor=CompanyMembership::lockForUpdate()->findOrFail($actor->id);
   abort_unless($actor->is_active && $actor->is_primary_responsible,403,'Accès à la gestion des utilisateurs retiré.');
   if(User::whereRaw('lower(email)=?',[$v['email']])->exists())throw ValidationException::withMessages(['email'=>'Cette adresse ne peut pas être utilisée. Choisissez une autre adresse.']);
   $u=User::create(['name'=>trim($v['name']),'email'=>$v['email'],'password'=>$v['password']]);
   $m=new CompanyMembership;$m->user_id=$u->id;$m->is_primary_responsible=($v['role']??'employe')==='responsable';$m->is_active=true;$m->save();
   $this->audit($actor,$m,'created',$m->is_primary_responsible?'Compte gérant créé par un gérant.':'Compte employé créé par un gérant.');return $m->id;
  });}catch(QueryException $e){if($e->getCode()==='23505')throw ValidationException::withMessages(['email'=>'Cette adresse ne peut pas être utilisée.']);throw $e;}
  return response()->json(['id'=>$id],201);
 }
 public function update(Request $r,int $member) {
  $actor=$this->actor($r);$v=$r->validate(['active'=>'required|boolean','reason'=>'required|string|min:3|max:500']);
  DB::transaction(function()use($actor,$member,$v){
   Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
   $actor=CompanyMembership::lockForUpdate()->findOrFail($actor->id);
   abort_unless($actor->is_active && $actor->is_primary_responsible,403,'Accès à la gestion des utilisateurs retiré.');
   $m=CompanyMembership::lockForUpdate()->findOrFail($member);
   abort_if(!$v['active'] && $m->is_primary_responsible && $m->is_active && CompanyMembership::where('is_primary_responsible',true)->where('is_active',true)->count()<=1,422,'Le dernier gérant actif doit conserver son accès.');
   if((bool)$m->is_active===$v['active'])return;
   $m->is_active=$v['active'];$m->save();
   DB::table('sessions')->where('user_id',$m->user_id)->delete();
   $this->audit($actor,$m,$v['active']?'enabled':'disabled',$v['reason']);
  });return response()->noContent();
 }
 public function profile(Request $r,int $member) {
  $actor=$this->actor($r);
  $r->merge(['email'=>mb_strtolower(trim((string)$r->input('email')))]);
  $v=$r->validate(['name'=>'required|string|max:150','email'=>'required|email|max:254','role'=>'required|in:employe,responsable','reason'=>'required|string|min:3|max:250','company_id'=>'prohibited','user_id'=>'prohibited']);
  try {DB::transaction(function()use($actor,$member,$v){
   Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
   $actor=CompanyMembership::lockForUpdate()->findOrFail($actor->id);
   abort_unless($actor->is_active && $actor->is_primary_responsible,403);
   $m=CompanyMembership::lockForUpdate()->findOrFail($member);
   $u=User::lockForUpdate()->findOrFail($m->user_id);
   $manager=$v['role']==='responsable';
   abort_if(!$manager && $m->is_primary_responsible && $m->is_active && CompanyMembership::where('is_primary_responsible',true)->where('is_active',true)->count()<=1,422,'Le dernier gérant actif doit conserver son rôle.');
   $changed=$u->name!==trim($v['name']) || $u->email!==$v['email'];
   abort_if($changed && DB::table('company_memberships')->where('user_id',$u->id)->where('company_id','<>',Tenant::id())->exists(),422,'Ce compte est partagé entre agences. Ses coordonnées ne peuvent pas être modifiées ici.');
   if(User::where('id','<>',$u->id)->whereRaw('lower(email)=?',[$v['email']])->exists())throw ValidationException::withMessages(['email'=>'Cette adresse ne peut pas être utilisée.']);
   $emailChanged=$u->email!==$v['email'];
   if($changed){$u->name=trim($v['name']);$u->email=$v['email'];if($emailChanged)$u->email_verified_at=null;$u->save();$this->audit($actor,$m,'profile_updated','Coordonnées modifiées. '.$v['reason']);}
   if($manager!==$m->is_primary_responsible){
    DB::table('company_memberships')->where('company_id',Tenant::id())->where('id',$m->id)->update(['is_primary_responsible'=>$manager,'updated_at'=>now()]);
    $this->audit($actor,$m,'role_changed',($manager?'Employé → Gérant. ':'Gérant → Employé. ').$v['reason']);
   }
   if($emailChanged || $manager!==$m->is_primary_responsible)DB::table('sessions')->where('user_id',$u->id)->delete();
  });}catch(QueryException $e){if($e->getCode()==='23505')throw ValidationException::withMessages(['email'=>'Cette adresse ne peut pas être utilisée.']);throw $e;}
  return response()->noContent();
 }
 public function password(Request $r,int $member) {
  $actor=$this->actor($r);$v=$r->validate(['password'=>'required|string|min:12|max:128|confirmed','reason'=>'required|string|min:3|max:250']);
  DB::transaction(function()use($actor,$member,$v){
   Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
   $actor=CompanyMembership::lockForUpdate()->findOrFail($actor->id);abort_unless($actor->is_active && $actor->is_primary_responsible,403);
   $m=CompanyMembership::lockForUpdate()->findOrFail($member);$u=User::lockForUpdate()->findOrFail($m->user_id);
   abort_if(DB::table('company_memberships')->where('user_id',$u->id)->where('company_id','<>',Tenant::id())->exists(),422,'Ce compte est partagé entre agences. Son mot de passe ne peut pas être réinitialisé ici.');
   $u->password=$v['password'];$u->remember_token=null;$u->save();
   DB::table('sessions')->where('user_id',$u->id)->delete();
   $this->audit($actor,$m,'password_reset',$v['reason']);
  });return response()->noContent();
 }
 private function audit($actor,$member,string $action,string $reason): void {
  DB::table('employee_access_events')->insert(['company_id'=>Tenant::id(),'actor_membership_id'=>$actor->id,'subject_membership_id'=>$member->id,'action'=>$action,'reason'=>$reason,'created_at'=>now()]);
 }
}
