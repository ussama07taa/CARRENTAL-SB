<?php
namespace App\Support;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
class BackupOperation
{
 public function enabled(): bool {
  return (int)config('backups.company_id')===Tenant::id() && DB::connection()->getDatabaseName()==='bensebih_pickup_sandbox_20261019' && is_file(config('backups.runner')?:'') && is_writable(config('backups.directory')?:'');
 }
 public function status(): ?array {
  if((int)config('backups.company_id')!==Tenant::id())return null;
  $root=config('backups.directory');if(!$root||!is_file($root.'/operation.json'))return null;
  $data=json_decode(file_get_contents($root.'/operation.json'),true);if(!is_array($data))return null;
  if(in_array($data['stage']??'', ['queued','copying','verifying'],true)){
   $handle=fopen($root.'/worker.lock','c');
   if($handle){$free=flock($handle,LOCK_EX|LOCK_NB);if($free){flock($handle,LOCK_UN);if(time()-strtotime($data['updated_at']??'')>30)$data['stage']='interrupted';}fclose($handle);}
  }
  return array_intersect_key($data,array_flip(['id','stage','started_at','updated_at']));
 }
 public function start(int $actor): array {
  abort_unless($this->enabled(),422,'La création locale n’est pas configurée sur ce serveur.');
  $root=config('backups.directory');$lock=fopen($root.'/dispatch.lock','c');abort_unless($lock&&flock($lock,LOCK_EX),503,'Création indisponible.');
  try{
   $current=$this->status();if($current&&in_array($current['stage'],['queued','copying','verifying'],true))return $current;
   $operation=['id'=>(string)\Illuminate\Support\Str::uuid(),'stage'=>'queued','started_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String(),'actor_user_id'=>$actor,'company_id'=>Tenant::id()];
   file_put_contents($root.'/operation.json',json_encode($operation),LOCK_EX);chmod($root.'/operation.json',0600);
   // An immutable local request receipt; archives and receipts are never HTTP downloads.
   $receipt=fopen($root.'/request-'.$operation['id'].'.json','x');fwrite($receipt,json_encode($operation));fclose($receipt);chmod($root.'/request-'.$operation['id'].'.json',0600);
   $command='nohup /usr/bin/python3 '.escapeshellarg(config('backups.runner')).' >> '.escapeshellarg($root.'/launcher.private.log').' 2>&1 < /dev/null &';
   Process::fromShellCommandline($command)->mustRun();
   return array_intersect_key($operation,array_flip(['id','stage','started_at','updated_at']));
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
