<?php
declare(strict_types=1);
require_once __DIR__.'/IndexBearClient.php';
require_once __DIR__.'/RocketIndexerClient.php';
require_once __DIR__.'/Generator.php';
final class IndexingQueue {
 public function __construct(private PDO $db,private string $locks,private ?IndexingApi $api=null,private ?Closure $clock=null,private string $credentialTag='',private string $provider='indexbear'){if(!in_array($provider,['indexbear','rocketindexer'],true))throw new InvalidArgumentException('Unknown indexing provider.');}
 private function now():int{return $this->clock?($this->clock)():time();}
 private function stamp(?int $time=null):string{return gmdate('Y-m-d H:i:s',$time??$this->now());}
 private function execute(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function ensureSchema():void {
  $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
  if(!is_dir($this->locks)&&!mkdir($this->locks,0700,true)&&!is_dir($this->locks))throw new RuntimeException('Could not create Indexing lock storage.');
  if($mysql){
   try{if($this->control('schema_version')==='7')return;}
   catch(PDOException $e){if((string)$e->getCode()!=='42S02'&&!str_contains($e->getMessage(),'no such table: eg_index_control'))throw $e;}
  }
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_batches (id VARCHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,job_id VARCHAR(32) NOT NULL,event VARCHAR(180) NOT NULL,name_number INT NULL,urls_json MEDIUMTEXT NOT NULL,total INT NOT NULL,accepted INT NOT NULL DEFAULT 0,mode VARCHAR(16) NOT NULL,start_time VARCHAR(5) NOT NULL,start_date VARCHAR(10) NULL,per_submit INT NOT NULL,gap_minutes INT NOT NULL,next_run_at VARCHAR(19) NULL,last_started_at VARCHAR(19) NULL,status VARCHAR(32) NOT NULL,last_error TEXT NOT NULL,created_at VARCHAR(19) NOT NULL,UNIQUE (user_id,job_id))'.$suffix);
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_submissions (id VARCHAR(32) PRIMARY KEY,batch_id VARCHAR(32) NOT NULL,start_offset INT NOT NULL,url_count INT NOT NULL,request_json MEDIUMTEXT NOT NULL,active_urls_json MEDIUMTEXT NULL,idempotency_key VARCHAR(80) NOT NULL,credential_tag VARCHAR(64) NOT NULL,provider_id VARCHAR(160) NULL,status VARCHAR(32) NOT NULL,credits_used INT NOT NULL DEFAULT 0,balance INT NULL,status_hint TEXT NOT NULL,last_error TEXT NOT NULL,created_at VARCHAR(19) NOT NULL,attempted_at VARCHAR(19) NULL,accepted_at VARCHAR(19) NULL,next_attempt_at VARCHAR(19) NULL,next_poll_at VARCHAR(19) NULL,uncertain INT NOT NULL DEFAULT 0,UNIQUE (batch_id,start_offset))'.$suffix);
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_control (name VARCHAR(80) PRIMARY KEY,value TEXT NOT NULL)'.$suffix);
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_names (user_id BIGINT NOT NULL,keyword_hash VARCHAR(64) NOT NULL,next_number INT NOT NULL,PRIMARY KEY (user_id,keyword_hash))'.$suffix);
  $columns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_batches':'PRAGMA table_info(eg_index_batches)')->fetchAll(PDO::FETCH_ASSOC);$names=array_column($columns,$mysql?'Field':'name');
  foreach(['deleted_at'=>'VARCHAR(19) NULL','start_date'=>'VARCHAR(10) NULL','name_number'=>'INT NULL','randomized_at'=>'VARCHAR(19) NULL','paused'=>'INT NOT NULL DEFAULT 0','provider'=>"VARCHAR(24) NOT NULL DEFAULT 'indexbear'"] as $column=>$type){
   if(in_array($column,$names,true))continue;
   try{$this->db->exec('ALTER TABLE eg_index_batches ADD COLUMN '.$column.' '.$type);}
   catch(PDOException $e){$columns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_batches':'PRAGMA table_info(eg_index_batches)')->fetchAll(PDO::FETCH_ASSOC);if(!in_array($column,array_column($columns,$mysql?'Field':'name'),true))throw $e;}
  }
  $subColumns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_submissions':'PRAGMA table_info(eg_index_submissions)')->fetchAll(PDO::FETCH_ASSOC);
  if(!in_array('active_urls_json',array_column($subColumns,$mysql?'Field':'name'),true)){
   try{$this->db->exec('ALTER TABLE eg_index_submissions ADD COLUMN active_urls_json MEDIUMTEXT NULL');}
   catch(PDOException $e){$subColumns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_submissions':'PRAGMA table_info(eg_index_submissions)')->fetchAll(PDO::FETCH_ASSOC);if(!in_array('active_urls_json',array_column($subColumns,$mysql?'Field':'name'),true))throw $e;}
  }
  foreach(['provider_timeline'=>'TEXT NULL','provider_data'=>'MEDIUMTEXT NULL'] as $column=>$type){
   $subColumns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_submissions':'PRAGMA table_info(eg_index_submissions)')->fetchAll(PDO::FETCH_ASSOC);
   if(in_array($column,array_column($subColumns,$mysql?'Field':'name'),true))continue;
   try{$this->db->exec('ALTER TABLE eg_index_submissions ADD COLUMN '.$column.' '.$type);}
   catch(PDOException $e){$subColumns=$this->db->query($mysql?'SHOW COLUMNS FROM eg_index_submissions':'PRAGMA table_info(eg_index_submissions)')->fetchAll(PDO::FETCH_ASSOC);if(!in_array($column,array_column($subColumns,$mysql?'Field':'name'),true))throw $e;}
  }
  $this->backfillNames();
  if($mysql)$this->control('schema_version','7');
 }
 private function namingLock(int $user):string{return substr(hash('sha256','indexing-names-'.$user),0,32);}
 private function allocateName(int $user,string $event):int {
  $hash=hash('sha256',mb_strtolower(trim(preg_replace('/\s+/u',' ',$event)),'UTF-8'));
  $number=$this->execute('SELECT next_number FROM eg_index_names WHERE user_id=? AND keyword_hash=?',[$user,$hash])->fetchColumn();
  if($number===false){$this->execute('INSERT INTO eg_index_names (user_id,keyword_hash,next_number) VALUES (?,?,?)',[$user,$hash,2]);return 1;}
  $this->execute('UPDATE eg_index_names SET next_number=? WHERE user_id=? AND keyword_hash=?',[(int)$number+1,$user,$hash]);return (int)$number;
 }
 private static function batchName(array $row):string {return $row['event'].((int)($row['name_number']??1)>1?' #'.(int)$row['name_number']:'');}
 private static function chunkName(array $batch,int $number):string {
  $prefix=$number.'-';$label=IndexBearClient::apiName(self::batchName($batch));$suffix=(int)($batch['name_number']??1)>1?' - '.(int)$batch['name_number']:'';
  if($suffix!==''&&str_ends_with($label,$suffix))$label=substr($label,0,-strlen($suffix));
  return $prefix.rtrim(substr($label,0,80-strlen($prefix)-strlen($suffix))).$suffix;
 }
 private function backfillNames():void {
  $users=$this->execute('SELECT DISTINCT user_id FROM eg_index_batches WHERE name_number IS NULL')->fetchAll(PDO::FETCH_COLUMN);
  foreach($users as $user){$this->locked($this->namingLock((int)$user),function()use($user){
   $this->db->beginTransaction();
   try{foreach($this->execute('SELECT id,event FROM eg_index_batches WHERE user_id=? AND name_number IS NULL ORDER BY created_at,id',[$user])->fetchAll(PDO::FETCH_ASSOC) as $row){$number=$this->allocateName((int)$user,$row['event']);$this->execute('UPDATE eg_index_batches SET name_number=? WHERE id=?',[$number,$row['id']]);}$this->db->commit();}
   catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  });}
 }
 public function control(string $name,?string $value=null):string {
  if($this->provider!=='indexbear'&&!in_array($name,['schema_version','worker_heartbeat'],true))$name=$this->provider.'_'.$name;
  if($value!==null){$exists=$this->execute('SELECT name FROM eg_index_control WHERE name=?',[$name])->fetchColumn();if($exists===false){try{$this->execute('INSERT INTO eg_index_control (name,value) VALUES (?,?)',[$name,$value]);}catch(PDOException $e){$this->execute('UPDATE eg_index_control SET value=? WHERE name=?',[$value,$name]);}}else $this->execute('UPDATE eg_index_control SET value=? WHERE name=?',[$value,$name]);}
  return (string)($this->execute('SELECT value FROM eg_index_control WHERE name=?',[$name])->fetchColumn()?:'');
 }
 public function cachedBalance():?array {
  $snapshot=json_decode($this->control('balance_snapshot'),true);
  if(!is_array($snapshot)||($snapshot['credential_tag']??null)!==$this->credentialTag)return null;
  unset($snapshot['credential_tag']);return $snapshot;
 }
 private function rememberBalance(int $balance,?int $cost=null):void {
  $cost=$cost??($this->cachedBalance()['cost']??10);
  $this->control('balance_snapshot',json_encode(['balance'=>max(0,$balance),'cost'=>max(1,$cost),'checked_at'=>gmdate('c',$this->now()),'credential_tag'=>$this->credentialTag],JSON_THROW_ON_ERROR));
 }
 public function refreshBalance():void {
  if(!$this->api||(int)$this->control('balance_refresh_after')>$this->now()||(int)$this->control('cooldown_until')>$this->now())return;
  $this->control('balance_refresh_after',(string)($this->now()+60));
  try{$out=$this->api->balance();$this->rememberBalance((int)($out['balance']??0),(int)($out['creditCosts']['google_instant']??10));$this->control('balance_error','');}
  catch(IndexBearError $e){$this->control('balance_error',$e->getMessage());if($e->kind==='rate_limited')$this->control('cooldown_until',(string)max((int)$this->control('cooldown_until'),$this->now()+$e->retryAfter));}
 }
 private function locked(string $id,callable $fn):mixed {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid Indexing batch.');
  $lock=fopen($this->locks.'/'.$id.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('This batch is already submitting or updating. Please wait.');}
  try{return $fn();}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function urls(string $text):array {
  $urls=[];
  foreach(preg_split('/\r\n|\n|\r/',$text) as $url){if(trim($url)==='')continue;if(strlen($url)>65535||preg_match('/[\x00-\x20\x7f]/',$url)||!preg_match('~^https?://~i',$url)||!filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Indexing needs complete HTTP or HTTPS URLs. Generate the batch with Domain Names filled in.');$urls[]=$url;}
  $urls=array_values(array_unique($urls,SORT_STRING));if(!$urls||count($urls)>1000)throw new InvalidArgumentException('Move a generated batch containing 1–1,000 URLs.');return $urls;
 }
 public function move(int $user,string $job,string $event,array $urls):array {
  $urls=self::urls(implode("\n",$urls));$event=mb_substr(trim($event),0,180);
  return $this->locked($this->namingLock($user),function()use($user,$job,$event,$urls){
   $old=$this->execute('SELECT id FROM eg_index_batches WHERE user_id=? AND job_id=?',[$user,$job])->fetchColumn();if($old){if(!empty($this->row($old)['deleted_at']))throw new InvalidArgumentException('This Indexing batch is in Trash. Restore it there to keep its submission history.');return $this->get($user,$old);}
   $id=bin2hex(random_bytes(16));$this->db->beginTransaction();
   try{$number=$this->allocateName($user,$event);$this->execute('INSERT INTO eg_index_batches (id,user_id,job_id,event,name_number,urls_json,total,mode,start_time,per_submit,gap_minutes,status,last_error,created_at,provider) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$user,$job,$event,$number,json_encode($urls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),count($urls),'manual','00:00',5,5,'manual','',$this->stamp(),$this->provider]);$this->db->commit();}
   catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return $this->get($user,$id);
  });
 }
 private function row(string $id):array{$row=$this->execute('SELECT * FROM eg_index_batches WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new InvalidArgumentException('Indexing batch not found.');return $row;}
 private static function domainBase(string $url):string {return substr($url,0,strcspn($url,'?#'));}
 private static function replaceBase(string $url,string $old,string $new):string {return self::domainBase($url)===$old?$new.substr($url,strlen($old)):$url;}
 public function urlText(int $user,string $id):string {
  $b=$this->row($id);if((int)$b['user_id']!==$user||!empty($b['deleted_at']))throw new InvalidArgumentException('Indexing batch not found.');
  $urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);return $urls?implode("\n",$urls)."\n":'';
 }
 public function reduce(int $user,string $id,int $count,?string $domain=null):array {
  return $this->locked($id,function()use($user,$id,$count,$domain){
   $this->get($user,$id);$b=$this->row($id);$urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$eligible=[];
   foreach($urls as $i=>$url)if($i>=(int)$b['accepted']&&($domain===null||self::domainBase($url)===$domain))$eligible[]=$i;
   if($count<1||$count>count($eligible))throw new InvalidArgumentException('Enter a whole number from 1 to '.count($eligible).' pending URLs to remove'.($domain!==null?' for this domain and path':'').'.');
   $removedIndices=array_fill_keys(array_slice($eligible,-$count),true);$removedUrls=[];$kept=[];
   foreach($urls as $i=>$url){if(isset($removedIndices[$i]))$removedUrls[$url]=true;else $kept[]=$url;}
   $total=count($kept);$changes=[];
   foreach($this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset>=? ORDER BY start_offset',[$id,$b['accepted']])->fetchAll(PDO::FETCH_ASSOC) as $sub){
    $offset=(int)$sub['start_offset'];$request=json_decode($sub['request_json'],true,512,JSON_THROW_ON_ERROR);$filtered=array_values(array_filter($request['urls'],fn($url)=>!isset($removedUrls[$url])));$changed=$filtered!==$request['urls'];
    if($changed&&((int)$sub['uncertain']||$sub['provider_id']!==null||$sub['accepted_at']!==null||!in_array($sub['status'],['prepared','retry','blocked'],true)))throw new InvalidArgumentException('An earlier submission is unconfirmed. Wait for confirmation before removing these URLs; they may already be accepted by the provider.');
    $newOffset=$offset;foreach($removedIndices as $i=>$_)if($i<$offset)$newOffset--;
    if(!$changed&&$newOffset===$offset)continue;
    $request['urls']=$filtered;$changes[]=[$sub['id'],$request,$newOffset,$changed];
   }
   $state=$b['status'];$next=$b['next_run_at'];$error=$b['last_error'];if($total===(int)$b['accepted']){$state='submitted';$next=null;$error='';}
   $this->db->beginTransaction();
   try{
    foreach($changes as [$sid,$request,$offset,$changed]){
     if(!$request['urls'])$this->execute('DELETE FROM eg_index_submissions WHERE id=?',[$sid]);
     elseif($changed)$this->execute('UPDATE eg_index_submissions SET request_json=?,url_count=?,idempotency_key=?,start_offset=? WHERE id=?',[json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),count($request['urls']),'eventforge-'.bin2hex(random_bytes(16)),$offset,$sid]);
     else $this->execute('UPDATE eg_index_submissions SET start_offset=? WHERE id=?',[$offset,$sid]);
    }
    $this->execute('UPDATE eg_index_batches SET urls_json=?,total=?,status=?,next_run_at=?,last_error=? WHERE id=?',[json_encode($kept,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$total,$state,$next,$error,$id]);$this->db->commit();
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return ['removed'=>$count,'batch'=>$this->get($user,$id)];
  });
 }
 public function randomize(int $user,string $id):array {
  return $this->locked($id,function()use($user,$id){
   $this->get($user,$id);$b=$this->row($id);$urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$offset=(int)$b['accepted'];$pending=array_slice($urls,$offset);
   if(count($pending)<2)return ['randomized'=>0,'batch'=>$this->get($user,$id)];
   $s=$this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset=?',[$id,$offset])->fetch(PDO::FETCH_ASSOC);
   if($s&&((int)$s['uncertain']||$s['provider_id']!==null||!in_array($s['status'],['prepared','retry','blocked'],true)))throw new InvalidArgumentException('An earlier submission is unconfirmed. Wait for confirmation before randomizing; its URLs may already be accepted by the provider.');
   // Fisher–Yates permutes complete strings; it does not parse or rebuild URLs.
   for($i=count($pending)-1;$i>0;$i--){$j=random_int(0,$i);[$pending[$i],$pending[$j]]=[$pending[$j],$pending[$i]];}
   $new=array_merge(array_slice($urls,0,$offset),$pending);$request=null;
   if($s){$candidate=json_decode($s['request_json'],true,512,JSON_THROW_ON_ERROR);$next=array_slice($pending,0,(int)$s['url_count']);if($candidate['urls']!==$next){$candidate['urls']=$next;$request=json_encode($candidate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}}
   $this->db->beginTransaction();
   try{
    $this->execute('UPDATE eg_index_batches SET urls_json=?,randomized_at=? WHERE id=?',[json_encode($new,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$this->stamp(),$id]);
    if($request!==null)$this->execute('UPDATE eg_index_submissions SET request_json=?,idempotency_key=? WHERE id=?',[$request,'eventforge-'.bin2hex(random_bytes(16)),$s['id']]);
    $this->db->commit();
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return ['randomized'=>count($pending),'batch'=>$this->get($user,$id)];
  });
 }
 public function addDomains(int $user,string $id,string $text):array {
  $domains=EventGenerator::urlDomains($text);if(!$domains)throw new InvalidArgumentException('Enter at least one new domain and path.');
  return $this->locked($id,function()use($user,$id,$domains){
   $this->get($user,$id);$b=$this->row($id);$urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$offset=(int)$b['accepted'];$pending=array_slice($urls,$offset);
   if(!$pending)return ['added'=>0,'redistributed'=>0,'batch'=>$this->get($user,$id)];
   $bases=[];foreach($urls as $url)$bases[self::domainBase($url)]=true;$newDomains=array_values(array_filter($domains,fn($d)=>!isset($bases[$d])));
   if(!$newDomains)return ['added'=>0,'redistributed'=>0,'batch'=>$this->get($user,$id)];
   foreach($newDomains as $domain)$bases[$domain]=true;$bases=array_keys($bases);
   if(count($bases)>count($pending))throw new InvalidArgumentException('There must be at least one pending URL per domain. Add fewer domains.');
   $quota=[];$counts=array_fill_keys($bases,0);$each=intdiv(count($pending),count($bases));$extra=count($pending)%count($bases);
   foreach($bases as $i=>$domain)$quota[$domain]=$each+($i<$extra?1:0);
   $move=[];foreach($pending as $i=>$url){$base=self::domainBase($url);if($counts[$base]<$quota[$base])$counts[$base]++;else $move[]=$i;}
   $cursor=0;foreach($bases as $domain)while($counts[$domain]<$quota[$domain]){$i=$move[$cursor++];$pending[$i]=$domain.substr($pending[$i],strlen(self::domainBase($pending[$i])));$counts[$domain]++;}
   $updated=array_merge(array_slice($urls,0,$offset),$pending);if(count(array_unique($updated,SORT_STRING))!==count($updated))throw new InvalidArgumentException('Redistribution would create duplicate URLs. Existing URLs were left unchanged.');self::urls(implode("\n",$updated));
   $changes=[];foreach($this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset>=?',[$id,$offset])->fetchAll(PDO::FETCH_ASSOC) as $submission){
    $request=json_decode($submission['request_json'],true,512,JSON_THROW_ON_ERROR);$next=array_slice($updated,(int)$submission['start_offset'],(int)$submission['url_count']);if($request['urls']===$next)continue;
    if((int)$submission['uncertain']||$submission['provider_id']!==null||!in_array($submission['status'],['prepared','retry','blocked'],true))throw new InvalidArgumentException('An earlier submission is unconfirmed. Wait for confirmation before redistributing its URLs.');
    $request['urls']=$next;$changes[]=[$submission['id'],json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'eventforge-'.bin2hex(random_bytes(16))];
   }
   $this->db->beginTransaction();try{
    $this->execute('UPDATE eg_index_batches SET urls_json=? WHERE id=?',[json_encode($updated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$id]);
    foreach($changes as [$sid,$json,$key])$this->execute('UPDATE eg_index_submissions SET request_json=?,idempotency_key=? WHERE id=?',[$json,$key,$sid]);$this->db->commit();
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return ['added'=>count($newDomains),'redistributed'=>count($move),'batch'=>$this->get($user,$id)];
  });
 }
 public function replaceDomain(int $user,string $id,string $old,string $new):array {
  $new=EventGenerator::urlDomain($new);if($new==='')throw new InvalidArgumentException('Enter the new domain and path.');
  if($old===$new)throw new InvalidArgumentException('Enter a different domain or path.');
  return $this->locked($id,function()use($user,$id,$old,$new){
   $this->get($user,$id);$b=$this->row($id);$urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$replaced=0;
   foreach($urls as $i=>&$url){if($i<(int)$b['accepted']||self::domainBase($url)!==$old)continue;$url=self::replaceBase($url,$old,$new);$replaced++;}unset($url);
   if(!$replaced)throw new InvalidArgumentException('No pending URLs use that domain and path. Refresh this batch.');
   if(count(array_unique($urls,SORT_STRING))!==count($urls))throw new InvalidArgumentException('This replacement would create duplicate URLs. Choose a different domain or path.');
   self::urls(implode("\n",$urls));$changes=[];
   foreach($this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset>=?',[$id,$b['accepted']])->fetchAll(PDO::FETCH_ASSOC) as $s){
    $request=json_decode($s['request_json'],true,512,JSON_THROW_ON_ERROR);$changed=false;
    foreach($request['urls'] as &$url){$value=self::replaceBase($url,$old,$new);if($value!==$url){$url=$value;$changed=true;}}unset($url);
    if(!$changed)continue;
    if((int)$s['uncertain']||$s['provider_id']!==null||!in_array($s['status'],['prepared','retry','blocked'],true))throw new InvalidArgumentException('An earlier submission using this domain is unconfirmed. Wait for confirmation before replacing it; its URLs may already be accepted by the provider.');
    $changes[]=[$s['id'],json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'eventforge-'.bin2hex(random_bytes(16))];
   }
   $this->db->beginTransaction();
   try{
    $this->execute('UPDATE eg_index_batches SET urls_json=? WHERE id=?',[json_encode($urls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$id]);
    foreach($changes as [$sid,$json,$key])$this->execute('UPDATE eg_index_submissions SET request_json=?,idempotency_key=? WHERE id=?',[$json,$key,$sid]);
    $this->db->commit();
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return ['replaced'=>$replaced,'batch'=>$this->get($user,$id)];
  });
 }
 public function removeDomain(int $user,string $id,string $domain):array {
  return $this->locked($id,function()use($user,$id,$domain){
   $this->get($user,$id);$b=$this->row($id);$urls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$kept=[];$accepted=0;$removed=0;
   foreach($urls as $i=>$url){if(self::domainBase($url)===$domain){$removed++;continue;}$kept[]=$url;if($i<(int)$b['accepted'])$accepted++;}
   if(!$removed)return ['removed'=>0,'batch'=>$this->get($user,$id)];
   $subs=$this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? ORDER BY created_at,id',[$id])->fetchAll(PDO::FETCH_ASSOC);$pending=[];$historical=[];$historyActive=[];$lowest=0;
   foreach($subs as $s){
    $lowest=min($lowest,(int)$s['start_offset']);
    if($s['accepted_at']!==null||$s['provider_id']!==null){if((int)$s['start_offset']>=0)$historical[]=$s['id'];$previous=$s['active_urls_json']!==null?json_decode($s['active_urls_json'],true,512,JSON_THROW_ON_ERROR):json_decode($s['request_json'],true,512,JSON_THROW_ON_ERROR)['urls'];$historyActive[]=[$s['id'],array_values(array_filter($previous,fn($url)=>self::domainBase($url)!==$domain))];continue;}
    $request=json_decode($s['request_json'],true,512,JSON_THROW_ON_ERROR);$filtered=array_values(array_filter($request['urls'],fn($url)=>self::domainBase($url)!==$domain));$changed=count($filtered)!==count($request['urls']);
    if($changed&&((int)$s['uncertain']||!in_array($s['status'],['prepared','retry','blocked'],true)))throw new InvalidArgumentException('An earlier submission using this domain is unconfirmed. Wait for confirmation before removing it; its URLs may already be accepted by the provider.');
    $request['urls']=$filtered;$pending[]=[$s,$request,$changed];
   }
   $state=$b['status'];$next=$b['next_run_at'];$error=$b['last_error'];if($accepted===count($kept)){$state='submitted';$next=null;$error='';}
   $this->db->beginTransaction();
   try{
    // Historical offsets leave the active cursor range; immutable payloads and provider IDs stay intact.
    foreach($historyActive as [$sid,$activeUrls])$this->execute('UPDATE eg_index_submissions SET active_urls_json=? WHERE id=?',[json_encode($activeUrls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$sid]);
    foreach($historical as $sid)$this->execute('UPDATE eg_index_submissions SET start_offset=? WHERE id=?',[--$lowest,$sid]);
    foreach($pending as [$s,$request,$changed]){
     if(!$request['urls']){$this->execute('DELETE FROM eg_index_submissions WHERE id=?',[$s['id']]);continue;}
     if($changed)$this->execute('UPDATE eg_index_submissions SET start_offset=?,url_count=?,request_json=?,idempotency_key=? WHERE id=?',[$accepted,count($request['urls']),json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'eventforge-'.bin2hex(random_bytes(16)),$s['id']]);
     else $this->execute('UPDATE eg_index_submissions SET start_offset=? WHERE id=?',[$accepted,$s['id']]);
    }
    $this->execute('UPDATE eg_index_batches SET urls_json=?,total=?,accepted=?,status=?,next_run_at=?,last_error=? WHERE id=?',[json_encode($kept,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),count($kept),$accepted,$state,$next,$error,$id]);$this->db->commit();
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
   return ['removed'=>$removed,'batch'=>$this->get($user,$id)];
  });
 }
 public function get(int $user,string $id):array {
  $b=$this->row($id);if((int)$b['user_id']!==$user||!empty($b['deleted_at']))throw new InvalidArgumentException('Indexing batch not found.');
  if(!$b['start_date']&&$b['next_run_at'])$b['start_date']=(new DateTimeImmutable($b['next_run_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d');
  if((int)$b['accepted']===(int)$b['total'])$b['next_run_at']=null;
  $b['keyword']=$b['event'];$b['event']=self::batchName($b);
  $allUrls=json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR);$domains=[];foreach($allUrls as $i=>$url){$base=self::domainBase($url);if(!isset($domains[$base]))$domains[$base]=['base'=>$base,'total'=>0,'pending'=>0];$domains[$base]['total']++;if($i>=(int)$b['accepted'])$domains[$base]['pending']++;}$b['domains']=array_values($domains);
  unset($b['urls_json']);foreach(['total','accepted','per_submit','gap_minutes','paused'] as $key)$b[$key]=(int)$b[$key];$b['remaining']=$b['total']-$b['accepted'];$b['completed']=0;$b['failed']=0;
  $active=array_fill_keys(array_slice($allUrls,0,$b['accepted']),true);
  $subs=$this->execute('SELECT id,provider_id,url_count,status,credits_used,balance,status_hint,last_error,created_at,attempted_at,accepted_at,next_attempt_at,request_json,active_urls_json,provider_timeline,provider_data FROM eg_index_submissions WHERE batch_id=? ORDER BY created_at DESC,id DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
  foreach($subs as &$sub){$sub['url_count']=(int)$sub['url_count'];$sub['credits_used']=(int)$sub['credits_used'];$request=json_decode($sub['request_json'],true,512,JSON_THROW_ON_ERROR);$count=0;$activeUrls=$sub['active_urls_json']!==null?json_decode($sub['active_urls_json'],true,512,JSON_THROW_ON_ERROR):$request['urls'];foreach($activeUrls as $url)if(isset($active[$url]))$count++;if(($b['provider']??'indexbear')==='rocketindexer'){$subActive=array_intersect_key($active,array_fill_keys($activeUrls,true));foreach((json_decode($sub['provider_data']??'{}',true)['items']??[]) as $item){$itemUrl=$item['url']??'';if(!isset($subActive[$itemUrl]))continue;unset($subActive[$itemUrl]);$state=strtolower(trim($item['status']??''));if($state==='delivered')++$b['completed'];elseif(in_array($state,['failed','error','rejected'],true))++$b['failed'];}}else{if($sub['status']==='completed')$b['completed']+=$count;if($sub['status']==='failed')$b['failed']+=$count;}unset($sub['request_json'],$sub['active_urls_json']);}unset($sub);$b['submissions']=$subs;$b['provider_locked']=false;foreach($subs as $sub)if($sub['attempted_at']!==null||$sub['provider_id']!==null)$b['provider_locked']=true;
  return $b;
 }
 public function listing(int $user):array{$ids=$this->execute('SELECT id FROM eg_index_batches WHERE user_id=? AND provider=? AND deleted_at IS NULL ORDER BY created_at DESC,id DESC',[$user,$this->provider])->fetchAll(PDO::FETCH_COLUMN);return array_map(fn($id)=>$this->get($user,$id),$ids);}
 public static function startSelection(string $date,string $time,int $now):array {
  if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Use 24-hour HH:MM. Midnight is 00:00; noon is 12:00.');
  $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Dhaka'));[$hour,$minute]=array_map('intval',explode(':',$time));$date=trim($date);
  if($date===''){$when=$local->setTime($hour,$minute);if($when->getTimestamp()<$now)$when=$when->modify('+1 day');}
  else{
   if(preg_match('/^[0-9]{1,2}$/D',$date)){$year=(int)$local->format('Y');$month=(int)$local->format('n');$day=(int)$date;}
   elseif(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$m)){[$year,$month,$day]=[(int)$m[1],(int)$m[2],(int)$m[3]];}
   else throw new InvalidArgumentException('Enter a day such as 5, or a full date YYYY-MM-DD.');
   if(!checkdate($month,$day,$year))throw new InvalidArgumentException('That day does not exist in the selected Bangladesh month.');
   $when=$local->setDate($year,$month,$day)->setTime($hour,$minute);
  }
  return ['date'=>$when->format('Y-m-d'),'utc'=>gmdate('Y-m-d H:i:s',$when->getTimestamp()),'timestamp'=>$when->getTimestamp()];
 }
 public function configure(int $user,string $id,string $mode,string $start,int $count,int $gap,string $date='',?string $provider=null):array {
  if(!in_array($mode,['manual','automatic'],true)||$count<1||$count>1000||$gap<1||$gap>10080)throw new InvalidArgumentException('Use 1–1,000 URLs and a gap of 1–10,080 minutes.');
  return $this->locked($id,function()use($user,$id,$mode,$start,$count,$gap,$date,$provider){
   $old=$this->get($user,$id);$b=$this->row($id);$b['start_date']=$old['start_date'];
   $provider??=$b['provider'];if(!in_array($provider,['indexbear','rocketindexer'],true))throw new InvalidArgumentException('Choose IndexBear or RocketIndexer.');
   if($provider==='rocketindexer'&&$count>100)throw new InvalidArgumentException('RocketIndexer batches support up to 100 URLs in EventForge.');
   if($provider!==$b['provider']){if($old['provider_locked'])throw new InvalidArgumentException('The provider cannot change after a submission attempt. Use a new batch.');$this->execute('DELETE FROM eg_index_submissions WHERE batch_id=? AND attempted_at IS NULL',[$id]);}

   if($b['status']==='review_required')throw new InvalidArgumentException('This batch has an uncertain old submission. Check it with the selected provider before further submissions.');
   $active=$b['mode']==='automatic'&&$b['start_time']===$start&&in_array($b['status'],['scheduled','running'],true);
   $selection=self::startSelection($date===''&&$active?($b['start_date']??''):$date,$start,$this->now());
   $next=null;$state=(int)$b['accepted']===(int)$b['total']?'submitted':$mode;
   if($mode==='automatic'&&$state!=='submitted'){
    $state='scheduled';
    if($active&&$selection['date']===$b['start_date']){$next=$b['next_run_at'];if($b['last_started_at']&&$gap!==(int)$b['gap_minutes'])$next=$this->stamp(strtotime($b['last_started_at'].' UTC')+$gap*60);}
    else{if($selection['timestamp']<$this->now())throw new InvalidArgumentException('The selected Bangladesh date and time are in the past. Choose a future day/time, or leave Date blank for the next occurrence.');$next=$selection['utc'];}
   }
   if($count!==(int)$b['per_submit'])$this->execute("DELETE FROM eg_index_submissions WHERE batch_id=? AND start_offset=? AND status='prepared' AND attempted_at IS NULL AND uncertain=0",[$id,$b['accepted']]);
   $this->execute('UPDATE eg_index_batches SET mode=?,start_time=?,start_date=?,per_submit=?,gap_minutes=?,next_run_at=?,status=?,last_error=?,provider=? WHERE id=?',[$mode,$start,$selection['date'],$count,$gap,$next,$state,'',$provider,$id]);return $this->get($user,$id);
  });
 }
 public function setPaused(int $user,string $id,bool $paused):array {
  return $this->locked($id,function()use($user,$id,$paused){
   $b=$this->get($user,$id);if($paused&&(!$b['remaining']||$b['mode']!=='automatic'))throw new InvalidArgumentException('Only automatic batches with pending URLs can be paused.');
   $this->execute('UPDATE eg_index_batches SET paused=? WHERE id=?',[$paused?1:0,$id]);return $this->get($user,$id);
  });
 }
 public function delete(int $user,string $id):void {
  $this->locked($id,function()use($user,$id){$this->get($user,$id);$this->execute('UPDATE eg_index_batches SET deleted_at=?,paused=1 WHERE id=? AND user_id=?',[$this->stamp(),$id,$user]);});
 }
 private function trashRow(int $user,string $id):array {
  $b=$this->row($id);if((int)$b['user_id']!==$user||empty($b['deleted_at']))throw new InvalidArgumentException('Deleted Indexing batch not found.');return $b;
 }
 public function trashed(int $user,int $page=1):array {
  $total=(int)$this->execute('SELECT COUNT(*) FROM eg_index_batches WHERE user_id=? AND deleted_at IS NOT NULL',[$user])->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=max(1,min($page,$pages));
  $rows=$this->execute('SELECT * FROM eg_index_batches WHERE user_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC,created_at DESC,id DESC LIMIT 50 OFFSET '.(($page-1)*50),[$user])->fetchAll(PDO::FETCH_ASSOC);
  return ['jobs'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
 }
 public function trashUrls(int $user,string $id):string {$b=$this->trashRow($user,$id);return implode("\n",json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR))."\n";}
 public function restoreTrash(int $user,string $id):void {
  $this->locked($id,function()use($user,$id){$this->trashRow($user,$id);$this->execute('UPDATE eg_index_batches SET deleted_at=NULL,paused=1 WHERE id=? AND user_id=?',[$id,$user]);});
 }
 public function purgeTrash(int $user,string $id):void {
  $this->locked($id,function()use($user,$id){
   $this->trashRow($user,$id);$this->db->beginTransaction();
   try{$this->execute('DELETE FROM eg_index_submissions WHERE batch_id=?',[$id]);$this->execute('DELETE FROM eg_index_batches WHERE id=? AND user_id=?',[$id,$user]);$this->db->commit();}
   catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  });
 }
 public function emptyTrash(int $user):array {
  $ids=$this->execute('SELECT id FROM eg_index_batches WHERE user_id=? AND deleted_at IS NOT NULL',[$user])->fetchAll(PDO::FETCH_COLUMN);$deleted=0;$skipped=0;
  foreach($ids as $id){try{$this->purgeTrash($user,$id);++$deleted;}catch(PDOException $e){throw $e;}catch(InvalidArgumentException|RuntimeException $e){++$skipped;}}return ['deleted'=>$deleted,'skipped'=>$skipped];
 }
 private function error(string $id,string $message,string $status='error'):void{$this->execute('UPDATE eg_index_batches SET status=?,last_error=? WHERE id=?',[$status,$message,$id]);}
 public function submitNow(int $user,string $id):array {
  $batch=$this->get($user,$id);
  if(!$batch['remaining'])throw new InvalidArgumentException('No pending URLs remain in this batch.');
  if($batch['status']==='review_required')throw new InvalidArgumentException('Check the earlier unconfirmed submission in IndexBear before submitting again.');
  $this->work($id,true,true);return $this->get($user,$id);
 }
 private function pendingSubmission(array $b):array {
  $id=$b['id'];$s=$this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset=?',[$id,$b['accepted']])->fetch(PDO::FETCH_ASSOC);
  if(!$s){
    $chunk=(int)$this->execute('SELECT COUNT(*) FROM eg_index_submissions WHERE batch_id=? AND start_offset<?',[$id,$b['accepted']])->fetchColumn()+1;
    $urls=array_slice(json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR),(int)$b['accepted'],(int)$b['per_submit']);$sid=bin2hex(random_bytes(16));$key='eventforge-'.$sid;
    $this->execute('INSERT INTO eg_index_submissions (id,batch_id,start_offset,url_count,request_json,idempotency_key,credential_tag,status,status_hint,last_error,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$sid,$id,$b['accepted'],count($urls),json_encode(['urls'=>$urls,'name'=>$chunk.'-'.self::batchName($b),'api_name'=>self::chunkName($b,$chunk)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$key,$this->credentialTag,'prepared','','',$this->stamp()]);
    $s=$this->execute('SELECT * FROM eg_index_submissions WHERE id=?',[$sid])->fetch(PDO::FETCH_ASSOC);
  }
  return $s;
 }
 public function work(string $id,bool $manual=false,bool $preserveSchedule=false):void {
  $this->locked($id,function()use($id,$manual,$preserveSchedule){
   $b=$this->row($id);if(!empty($b['deleted_at'])||$b['provider']!==$this->provider)return;$now=$this->now();if(!$manual&&!empty($b['paused']))return;if($b['status']==='review_required'||(int)$b['accepted']>=(int)$b['total'])return;
   if($manual&&!$preserveSchedule&&$b['mode']!=='manual')throw new InvalidArgumentException('Switch this batch to Manual and save before Submit Now.');
   if(!$manual&&($b['mode']!=='automatic'||!in_array($b['status'],['scheduled','running'],true)||!$b['next_run_at']||$b['next_run_at']>$this->stamp()))return;
   if(!$this->api){$this->error($id,'Connect the selected provider’s API key on this page.');return;}
   $s=$this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND start_offset=?',[$id,$b['accepted']])->fetch(PDO::FETCH_ASSOC);
   if($this->provider==='rocketindexer'&&$s&&(int)$s['uncertain']){$this->error($id,'RocketIndexer did not confirm the earlier request. Check its dashboard; it will not be resubmitted automatically.','review_required');return;}
   if($s&&(int)$s['uncertain']&&$s['credential_tag']!==$this->credentialTag){$this->error($id,'The API key changed while an earlier submission was unconfirmed. Check the selected provider before resubmitting.','review_required');return;}
   if($s&&$s['next_attempt_at']&&$s['next_attempt_at']>$this->stamp())return;
   if($s&&(int)$s['uncertain']&&$now-strtotime($s['created_at'].' UTC')>=23*3600){$this->error($id,'An earlier request could not be confirmed within the safe idempotency window. Check the selected provider before resubmitting.','review_required');return;}
   $s=$s?:$this->pendingSubmission($b);
   $request=json_decode($s['request_json'],true,512,JSON_THROW_ON_ERROR);
   $cooldown=(int)$this->control('cooldown_until');if($cooldown>$now){$this->execute('UPDATE eg_index_batches SET last_error=? WHERE id=?',['Indexing provider rate limit: wait until '.gmdate('H:i:s',$cooldown+21600).' Bangladesh time.',$id]);return;}
   $started=$this->stamp();
   // Mark uncertain before the network call, so a process crash also reuses this request/key.
   $this->execute('UPDATE eg_index_submissions SET status=?,created_at=CASE WHEN attempted_at IS NULL THEN ? ELSE created_at END,attempted_at=?,uncertain=1 WHERE id=?',['submitting',$started,$started,$s['id']]);$this->execute('UPDATE eg_index_batches SET status=?,last_error=? WHERE id=?',['running','',$id]);
   try{
    $apiName=$request['api_name']??(substr(trim(preg_replace('/[^A-Za-z0-9 ._-]/',' ',$request['name'])),0,80)?:'Event batch');
    $out=$this->api instanceof IndexBearClient?$this->api->submitPrepared($request['urls'],$apiName,$s['idempotency_key']):$this->api->submit($request['urls'],$apiName,$s['idempotency_key']);
    if(!is_string($out['submissionId']??null)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$out['submissionId'])||(int)($out['urlCount']??-1)!==(int)$s['url_count'])throw new IndexBearError('The provider returned an unexpected URL count or submission ID.');
    if(isset($out['providerData']))$this->execute('UPDATE eg_index_submissions SET provider_data=? WHERE id=?',[json_encode($out['providerData'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$s['id']]);
    $this->db->beginTransaction();
    $this->execute('UPDATE eg_index_submissions SET status=?,provider_id=?,credits_used=?,balance=?,accepted_at=?,next_poll_at=?,uncertain=0,status_hint=?,last_error=? WHERE id=?',['queued',$out['submissionId'],(int)($out['creditsUsed']??0),isset($out['balance'])?(int)$out['balance']:null,$this->stamp(),$this->stamp($this->now()+60),mb_substr((string)($out['estimatedCompletion']??''),0,2000),'',$s['id']]);
    $accepted=(int)$b['accepted']+(int)$s['url_count'];$next=$b['mode']==='automatic'?$this->stamp(strtotime($started.' UTC')+(int)$b['gap_minutes']*60):null;$state=$accepted===(int)$b['total']?'submitted':($b['mode']==='automatic'?'scheduled':'manual');
    $lastStarted=$started;
    if($preserveSchedule&&$b['mode']==='automatic'){$next=$b['next_run_at'];$lastStarted=$b['last_started_at'];}
    if($accepted===(int)$b['total'])$next=null;
    $this->execute('UPDATE eg_index_batches SET accepted=?,status=?,next_run_at=?,last_started_at=?,last_error=? WHERE id=?',[$accepted,$state,$next,$lastStarted,'',$id]);$this->db->commit();
    if(isset($out['balance'])){try{$this->rememberBalance((int)$out['balance']);}catch(PDOException $e){/* A cache write must not undo an accepted submission. */}}
   }catch(RocketSubmissionReview $e){
    if($this->db->inTransaction())$this->db->rollBack();
    $this->execute('UPDATE eg_index_submissions SET status=?,last_error=?,provider_data=?,next_attempt_at=NULL,uncertain=1 WHERE id=?',['review_required',$e->getMessage(),json_encode($e->providerData,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$s['id']]);$this->error($id,$e->getMessage(),'review_required');
   }catch(IndexBearError $e){
    if($this->db->inTransaction())$this->db->rollBack();
    if($this->provider==='rocketindexer'&&!$e->definite){$message=$e->getMessage().' Automatic resubmission is paused; check RocketIndexer first.';$this->execute('UPDATE eg_index_submissions SET status=?,last_error=?,next_attempt_at=NULL,uncertain=1 WHERE id=?',['review_required',$message,$s['id']]);$this->error($id,$message,'review_required');return;}
    $retry=$e->kind==='rate_limited'||!$e->definite;
    $this->execute('UPDATE eg_index_submissions SET status=?,last_error=?,next_attempt_at=?,uncertain=? WHERE id=?',[$retry?'retry':'blocked',$e->getMessage(),$retry?$this->stamp($this->now()+$e->retryAfter):null,(!$e->definite||(int)$s['uncertain'])?1:0,$s['id']]);
    if($e->kind==='rate_limited')$this->control('cooldown_until',(string)max((int)$this->control('cooldown_until'),$this->now()+$e->retryAfter));
    $this->error($id,$e->getMessage(),$retry?($b['mode']==='automatic'?'scheduled':'manual'):'error');
   }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($this->provider==='rocketindexer'){$message='RocketIndexer submission was interrupted. Check its dashboard before resubmitting.';$this->execute('UPDATE eg_index_submissions SET status=?,last_error=?,uncertain=1,next_attempt_at=NULL WHERE id=?',['review_required',$message,$s['id']]);$this->error($id,$message,'review_required');return;}$this->execute('UPDATE eg_index_submissions SET status=?,last_error=?,next_attempt_at=? WHERE id=?',['retry','The request was interrupted. Its next attempt will reuse the same idempotency key.',$this->stamp($this->now()+60),$s['id']]);$this->error($id,'Submission interrupted; retrying with the same request key.',$b['mode']==='automatic'?'scheduled':'manual');}
  });
 }
 public function poll(string $id):void {
  if(!$this->api||(int)$this->control('cooldown_until')>$this->now())return;
  $this->locked($id,function()use($id){
   $batch=$this->row($id);if(!empty($batch['deleted_at'])||$batch['provider']!==$this->provider)return;
   $predicate=$this->provider==='rocketindexer'?"status IN ('queued','processing','completed','failed') AND next_poll_at IS NOT NULL AND next_poll_at<=?":"(status IN ('queued','processing') OR (status='completed' AND provider_timeline IS NULL)) AND (next_poll_at IS NULL OR next_poll_at<=?)";
   $due=$this->execute('SELECT * FROM eg_index_submissions WHERE batch_id=? AND '.$predicate.' ORDER BY next_poll_at LIMIT 1',[$id,$this->stamp()])->fetchAll(PDO::FETCH_ASSOC);
   foreach($due as $s){
    $this->execute('UPDATE eg_index_submissions SET next_poll_at=? WHERE id=?',[$this->stamp($this->now()+60),$s['id']]);
    try{
     $out=$this->api instanceof RocketIndexerClient?$this->api->statusBatch(json_decode($s['provider_data']??'{}',true)?:[]):$this->api->status($s['provider_id']);$timeline=json_decode($s['provider_timeline']??'{}',true)?:[];
     foreach(['createdAt','sentAt','completedAt'] as $field){$value=$out[$field]??null;if(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)){try{$timeline[$field]=(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');}catch(Exception $e){/* Ignore malformed optional provider timestamps. */}}}
     if(isset($out['providerData'])){
      $next=($out['keepPolling']??true)&&$this->now()-strtotime($s['accepted_at'].' UTC')<7*86400?$this->stamp($this->now()+($out['status']==='processing'?60:300)):null;
      $this->execute('UPDATE eg_index_submissions SET provider_data=?,next_poll_at=? WHERE id=?',[json_encode($out['providerData'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$next,$s['id']]);
     }
     $this->execute('UPDATE eg_index_submissions SET status=?,status_hint=?,last_error=?,provider_timeline=? WHERE id=?',[$out['status'],mb_substr((string)($out['statusHint']??''),0,2000),'',json_encode($timeline,JSON_THROW_ON_ERROR),$s['id']]);
    }
    catch(IndexBearError $e){$this->execute('UPDATE eg_index_submissions SET last_error=? WHERE id=?',[$e->getMessage(),$s['id']]);if($e->kind==='rate_limited'){$this->control('cooldown_until',(string)max((int)$this->control('cooldown_until'),$this->now()+$e->retryAfter));break;}}
   }
  });
 }
 public function refresh(int $user):void {
  $id=$this->execute("SELECT s.batch_id FROM eg_index_submissions s JOIN eg_index_batches b ON b.id=s.batch_id WHERE b.user_id=? AND b.deleted_at IS NULL AND s.status IN ('queued','processing') AND s.next_poll_at<=? ORDER BY s.next_poll_at LIMIT 1",[$user,$this->stamp()])->fetchColumn();
  if($id){try{$this->poll($id);}catch(RuntimeException $e){/* A concurrent worker already owns this batch. */}}
 }
 public function cycle(?int $user=null):void {
  $rows=$this->execute('SELECT id FROM eg_index_batches WHERE provider=? AND deleted_at IS NULL'.($user!==null?' AND user_id=?':''),$user!==null?[$this->provider,$user]:[$this->provider])->fetchAll(PDO::FETCH_COLUMN);
  foreach($rows as $id){try{$this->control('worker_heartbeat',(string)$this->now());$this->work($id);$this->control('worker_heartbeat',(string)$this->now());$this->poll($id);}catch(Throwable $e){/* A locked or failing batch must not stop other event batches. */}}
 }
}
