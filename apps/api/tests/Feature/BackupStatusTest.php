<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
class BackupStatusTest extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_status_is_manager_only_tenant_bound_and_never_exposes_secrets(): void
 {
  $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
  $company=Company::factory()->create();
  [$manager,$staff]=Tenant::run($company->id,fn()=>[CompanyMembership::factory()->responsible()->create(),CompanyMembership::factory()->create()]);
  $root=sys_get_temp_dir().'/backup-status-'.bin2hex(random_bytes(8));
  $dir=$root.'/20261027T145336544319Z';File::makeDirectory($dir,0700,true);
  try {
   config(['backups.directory'=>$root,'backups.company_id'=>$company->id]);
   file_put_contents($dir.'/manifest.json',json_encode(['source_database'=>DB::connection()->getDatabaseName(),'secret'=>'DO-NOT-EXPOSE']));
   file_put_contents($dir.'/verification.json',json_encode(['verified'=>true,'restored_database'=>DB::connection()->getDatabaseName(),'files'=>3,'rows'=>12,'isolated_socket'=>'SECRET-PATH']));
   foreach(['database.dump','documents.tar.gz','application.tar.gz','environment.private','archive-checksums.json'] as $name)file_put_contents($dir.'/'.$name,'SECRET');
   $this->actingAs($manager->user)->withSession(['company_id'=>$company->id]);
   $response=$this->getJson('/api/v1/settings/backups')->assertOk()->assertJsonPath('configured',true)->assertJsonPath('history.0.status','verified')->assertJsonPath('history.0.files',3)->assertJsonPath('automatic',false)->assertJsonPath('offsite',false);
   $this->assertStringNotContainsString('SECRET',$response->getContent());
   $this->assertStringNotContainsString($root,$response->getContent());
   $checksums=[];
   foreach(['database.dump','documents.tar.gz','application.tar.gz','environment.private','manifest.json'] as $file)$checksums[$file]=hash_file('sha256',$dir.'/'.$file);
   file_put_contents($dir.'/archive-checksums.json',json_encode($checksums));
   $url='/api/v1/settings/backups/20261027T145336544319Z/download';
   $credentials=['password'=>'Test-only-strong-password','password_confirmation'=>'Test-only-strong-password'];
   $this->postJson($url,['password'=>'short'])->assertUnprocessable();
   $download=$this->postJson($url,$credentials)->assertOk();
   $archive=$download->baseResponse->getFile()->getPathname();
   $zip=new \ZipArchive;$zip->open($archive);$zip->setPassword('wrong');
   $this->assertFalse($zip->getFromName('environment.private'));
   $zip->setPassword($credentials['password']);
   $this->assertSame('SECRET',$zip->getFromName('environment.private'));
   $this->assertSame(7,$zip->numFiles);
   for($i=0;$i<$zip->numFiles;$i++)$this->assertSame(\ZipArchive::EM_AES_256,$zip->statIndex($i)['encryption_method']);
   $zip->close();unlink($archive);
   foreach(glob($root.'/download-*.json') as $receipt)$this->assertStringNotContainsString($credentials['password'],file_get_contents($receipt));
   file_put_contents($dir.'/database.dump','changed');
   $this->postJson($url,$credentials)->assertConflict();
   unlink($dir.'/database.dump');
   $this->getJson('/api/v1/settings/backups')->assertJsonPath('history.0.status','unverified');
   file_put_contents($dir.'/verification.json','invalid');
   $this->getJson('/api/v1/settings/backups')->assertOk()->assertJsonPath('history.0.status','unverified');
   $this->postJson('/api/v1/settings/backups')->assertUnprocessable();
   $operation=['id'=>'existing-operation','stage'=>'queued','started_at'=>now()->toIso8601String(),'updated_at'=>now()->toIso8601String()];
   file_put_contents($root.'/operation.json',json_encode($operation));
   $service=\Mockery::mock(\App\Support\BackupOperation::class)->makePartial();
   $service->shouldReceive('enabled')->andReturn(true);
   $this->app->instance(\App\Support\BackupOperation::class,$service);
   $this->postJson('/api/v1/settings/backups')->assertStatus(202)->assertJsonPath('operation.id','existing-operation');
   $this->postJson('/api/v1/settings/backups')->assertStatus(202)->assertJsonPath('operation.id','existing-operation');
   $this->assertCount(0,glob($root.'/request-*.json'));
   $operation['updated_at']=now()->subMinutes(2)->toIso8601String();file_put_contents($root.'/operation.json',json_encode($operation));
   $this->getJson('/api/v1/settings/backups')->assertJsonPath('operation.stage','interrupted');
   $this->app->forgetInstance(\App\Support\BackupOperation::class);
   $this->actingAs($staff->user)->getJson('/api/v1/settings/backups')->assertForbidden();
   $this->postJson('/api/v1/settings/backups')->assertForbidden();
   $this->postJson($url,$credentials)->assertForbidden();
   $other=Company::factory()->create();$owner=Tenant::run($other->id,fn()=>CompanyMembership::factory()->responsible()->create());
   $this->actingAs($owner->user)->withSession(['company_id'=>$other->id])->getJson('/api/v1/settings/backups')->assertOk()->assertJsonPath('configured',false)->assertJsonCount(0,'history');
   $this->postJson($url,$credentials)->assertNotFound();
  } finally {File::deleteDirectory($root);}
 }
}
