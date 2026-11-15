<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('EVENTGEN',true);require dirname(__DIR__).'/app/bootstrap.php';require_once ROOT.'/app/IndexingProviders.php';require_once ROOT.'/app/IndexingWorkerRecovery.php';
if(!is_file(ROOT.'/config.php')){fwrite(STDERR,"Install EventForge before starting the Indexing worker.\n");exit(1);}
$once=in_array('--once',$argv,true);$stop=false;$exitCode=0;
if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGTERM,static function()use(&$stop){$stop=true;});pcntl_signal(SIGINT,static function()use(&$stop){$stop=true;});}
$lock=fopen(ROOT.'/storage/indexing-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"An Indexing worker is already running or storage is not writable.\n");exit(1);}
try{
 do{
  try{
   foreach(['indexbear','rocketindexer'] as $provider){
    try{$saved=setting($provider.'_api_key');$plain=$saved!==''?cryptKey($saved,true):'';$api=$plain!==''?($provider==='rocketindexer'?new RocketIndexerClient($plain):new IndexBearClient($plain)):null;$queue=new IndexingQueue(db(),ROOT.'/storage/indexing-locks',$api,null,$plain!==''?hash('sha256',$plain):'',$provider);$queue->ensureSchema();$queue->control('worker_heartbeat',(string)time());$queue->cycle();$queue->control('worker_heartbeat',(string)time());$queue->refreshBalance();$queue->control('worker_heartbeat',(string)time());}
    catch(Throwable $e){
     fwrite(STDERR,gmdate('c').' '.$provider.' cycle failed: '.$e->getMessage()."\n");
     // A dead PDO is cached for this process. Release locks and let systemd
     // start a fresh process; never replay a query or submission here.
     if(IndexingWorkerRecovery::databaseLost($e)){$exitCode=75;$stop=true;break;}
    }
   }

  }catch(Throwable $e){fwrite(STDERR,gmdate('c')." Indexing worker cycle failed: ".$e->getMessage()."\n");if(IndexingWorkerRecovery::databaseLost($e)){$exitCode=75;$stop=true;}}
  if(!$once&&!$stop)sleep(10);
 }while(!$once&&!$stop);
}finally{flock($lock,LOCK_UN);fclose($lock);}

if($exitCode===75)fwrite(STDERR,gmdate('c')." Database connection lost; exiting for automatic service restart with a fresh connection.\n");
exit($exitCode);
