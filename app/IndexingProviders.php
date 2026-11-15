<?php
declare(strict_types=1);
require_once __DIR__.'/IndexingQueue.php';
function indexingProviderName(string $provider):string {
 return match($provider){'indexbear'=>'IndexBear','rocketindexer'=>'RocketIndexer',default=>throw new InvalidArgumentException('Choose IndexBear or RocketIndexer.')};
}
function indexingQueue(string $provider='indexbear'):IndexingQueue {
 indexingProviderName($provider);static $queues=[];
 if(!isset($queues[$provider])){
  $key=setting($provider.'_api_key');$plain=$key!==''?cryptKey($key,true):'';
  $api=$plain!==''?($provider==='rocketindexer'?new RocketIndexerClient($plain):new IndexBearClient($plain)):null;
  $queues[$provider]=new IndexingQueue(db(),ROOT.'/storage/indexing-locks',$api,null,$plain!==''?hash('sha256',$plain):'',$provider);$queues[$provider]->ensureSchema();
 }
 return $queues[$provider];
}
function indexingAllBatches(int $user):array {
 $all=[];foreach(['indexbear','rocketindexer'] as $provider){$queue=indexingQueue($provider);$cooldown=(int)$queue->control('cooldown_until');foreach($queue->listing($user) as $batch){$batch['provider_cooldown']=$cooldown;$all[]=$batch;}}
 return $all;
}
