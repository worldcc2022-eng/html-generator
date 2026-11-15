<?php
declare(strict_types=1);
require_once __DIR__.'/KeywordTemplate.php';
final class TitleBank {
 public function __construct(private PDO $db){}
 public function ensureSchema(string $storageDir=''):void{
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_title_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bank_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_text TEXT NOT NULL, sort_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, UNIQUE KEY bank_template (bank_id,template_hash), KEY random_pick (bank_id,sort_key,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_title_used (keyword_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, title_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (keyword_hash,template_hash), UNIQUE KEY keyword_title (keyword_hash,title_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  if($storageDir!=='')$this->backfill($storageDir);
 }
 private function backfill(string $storageDir):void{
  $this->db->beginTransaction();
  try{
   $q=$this->db->prepare('INSERT IGNORE INTO eg_settings (name,value) VALUES (?,?)');$q->execute(['title_history_backfilled','0']);
   $q=$this->db->prepare('SELECT value FROM eg_settings WHERE name=? FOR UPDATE');$q->execute(['title_history_backfilled']);
   if($q->fetchColumn()==='1'){$this->db->commit();return;}
   $jobs=$this->db->query('SELECT id,event FROM eg_jobs');
   $insert=$this->db->prepare('INSERT IGNORE INTO eg_title_used (keyword_hash,template_hash,title_hash,job_id) VALUES (?,?,?,?)');
   foreach($jobs->fetchAll(PDO::FETCH_ASSOC) as $job){
    if(!preg_match('/^[a-f0-9]{32}$/',$job['id']))continue;
    $file=$storageDir.'/'.$job['id'].'/state.json';if(!is_file($file))continue;
    $state=json_decode(file_get_contents($file),true);if(($state['generator']??'event')==='movie')continue;$keyword=self::keywordHash($job['event']);
    foreach($state['rows']??[] as $row){if(!is_string($row['title']??null))continue;$hash=hash('sha256',self::normalized($row['title']));$insert->execute([$keyword,hash('sha256','prior-title:'.$hash),$hash,$job['id']]);}
   }
   $this->db->prepare('UPDATE eg_settings SET value=? WHERE name=?')->execute(['1','title_history_backfilled']);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private static function normalized(string $v):string{return mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)),'UTF-8');}
 public static function keywordHash(string $event):string{return hash('sha256',self::normalized($event));}
 public static function templateHash(string $template):string{return hash('sha256',self::normalized($template));}
 public static function render(string $template,string $event,?DateTimeImmutable $date,string $eventType='Event'):string{
  $template=KeywordTemplate::withoutDuplicates($template,$event);
  $year=$date?->format('Y')??'';$hasYear=preg_match('/(?<![0-9])'.preg_quote($year,'/').'(?![0-9])/u',$event)===1;
  $dateText=$date?->format($hasYear?'j F':'j F Y')??'';
  $template=preg_replace_callback('/\(\s*\{(?:event_date|date)\}\s*\)|\{(?:event_date|date)\}/u',fn()=>$dateText!==''?'('.$dateText.')':'',$template);
  return strtr($template,['{event_name}'=>$event,'{event}'=>$eventType]);
 }
 public static function validateTemplate(string $line,int $number):string{
  $line=trim($line);if(!mb_check_encoding($line,'UTF-8')||mb_strlen($line)>2048||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$line))throw new InvalidArgumentException('Invalid UTF-8 title or title over 2,048 characters at line '.$number.'.');
  if(!str_contains($line,'{event_name}'))throw new InvalidArgumentException('Line '.$number.' must contain {event_name}. One title template per line.');
  return $line;
 }
 public function info(bool $lock=false):array{
  $q=$this->db->prepare('SELECT value FROM eg_settings WHERE name=?'.($lock?' FOR UPDATE':''));$q->execute(['title_bank']);return json_decode($q->fetchColumn()?:'{}',true)?:[];
 }
 public function import($stream,string $filename,string $mode,string $lockPath):array{
  if(!is_resource($stream)||!in_array($mode,['append','replace'],true))throw new InvalidArgumentException('Choose a TXT file or paste one title per line.');
  $guard=fopen($lockPath,'c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Another title import is running. Try again shortly.');
  $bank=bin2hex(random_bytes(16));$published=false;$lineNumber=0;$provided=0;$values=[];$before=0;
  try{
   $old=$this->info();
   if($mode==='append'&&!empty($old['id'])){
    $q=$this->db->prepare('INSERT INTO eg_title_entries (bank_id,template_hash,template_text,sort_key) SELECT ?,template_hash,template_text,sort_key FROM eg_title_entries WHERE bank_id=?');$q->execute([$bank,$old['id']]);$before=(int)$q->rowCount();
   }
   $flush=function()use(&$values,$bank){
    if(!$values)return;
    $q=$this->db->prepare('INSERT IGNORE INTO eg_title_entries (bank_id,template_hash,template_text,sort_key) VALUES '.implode(',',array_fill(0,count($values),'(?,?,?,?)')));$args=[];
    foreach($values as $line)array_push($args,$bank,self::templateHash($line),$line,bin2hex(random_bytes(16)));
    $q->execute($args);$values=[];
   };
   while(($raw=fgets($stream,65537))!==false){
    ++$lineNumber;if($lineNumber===1)$raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
    if(trim($raw)==='')continue;
    $values[]=self::validateTemplate($raw,$lineNumber);++$provided;if(count($values)===300)$flush();
   }
   $flush();if(!$provided)throw new InvalidArgumentException('No title templates found. Paste titles or upload a UTF-8 TXT file.');
   $q=$this->db->prepare('SELECT COUNT(*) FROM eg_title_entries WHERE bank_id=?');$q->execute([$bank]);$count=(int)$q->fetchColumn();
   $info=['id'=>$bank,'count'=>$count,'filename'=>mb_substr($filename,0,255),'saved_at'=>gmdate('c'),'added'=>$count-$before];
   $this->db->beginTransaction();
   $q=$this->db->prepare('INSERT INTO eg_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$q->execute(['title_bank',json_encode($info,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$this->db->commit();$published=true;
   // Active jobs already snapshot their titles. History stays separate and is never reset.
   $q=$this->db->prepare('DELETE FROM eg_title_entries WHERE bank_id<>?');$q->execute([$bank]);
   return $info;
  }finally{
   if($this->db->inTransaction())$this->db->rollBack();
   if(!$published){$q=$this->db->prepare('DELETE FROM eg_title_entries WHERE bank_id=?');$q->execute([$bank]);}
   flock($guard,LOCK_UN);fclose($guard);
  }
 }
 public static function percentages(mixed $unicode,mixed $fresh):array {
  $options=['options'=>['min_range'=>0,'max_range'=>100]];
  $unicode=filter_var($unicode,FILTER_VALIDATE_INT,$options);$fresh=filter_var($fresh,FILTER_VALIDATE_INT,$options);
  if($unicode===false||$fresh===false||$unicode+$fresh!==100)throw new InvalidArgumentException('Unicode % and Fresh % must be whole numbers from 0 to 100 and total 100.');
  return [$unicode,$fresh];
 }
 public function pick(string $event,?DateTimeImmutable $date,int $count,string $job,string $eventType='Event',int $unicodePercent=50):array{
  if(!$this->db->inTransaction())throw new LogicException('Title reservations must be transactional.');
  $info=$this->info(true);if(empty($info['id']))throw new InvalidArgumentException('Save your title templates in Settings first. Each line must contain {event_name}.');
  $keyword=self::keywordHash($event);$pivot=bin2hex(random_bytes(16));$titles=[];$limit=min(2000,max(100,$count*2));
  if($unicodePercent<0||$unicodePercent>100)throw new InvalidArgumentException('Unicode % must be between 0 and 100.');
  $unicodeCount=intdiv($count*$unicodePercent+50,100);$targets=[$unicodeCount,$count-$unicodeCount];$picked=[0,0];
  $date=$date??new DateTimeImmutable('now',new DateTimeZone('-05:00'));
  $reserve=$this->db->prepare('INSERT IGNORE INTO eg_title_used (keyword_hash,template_hash,title_hash,job_id) VALUES (?,?,?,?)');
  foreach(['>=','<'] as $side){
   $cursor=null;$lastId=0;
   while(count($titles)<$count){
    $where='e.bank_id=? AND e.sort_key '.$side.' ?';$args=[$info['id'],$pivot];
    if($cursor!==null){$where.=' AND (e.sort_key>? OR (e.sort_key=? AND e.id>?))';array_push($args,$cursor,$cursor,$lastId);}
    $where.=' AND NOT EXISTS (SELECT 1 FROM eg_title_used u WHERE u.keyword_hash=? AND u.template_hash=e.template_hash)';$args[]=$keyword;
    $q=$this->db->prepare('SELECT e.id,e.template_hash,e.template_text,e.sort_key FROM eg_title_entries e WHERE '.$where.' ORDER BY e.sort_key,e.id LIMIT '.$limit);$q->execute($args);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$candidates)break;
    // The indexed random starting point keeps large banks fast; shuffle the sampled window too.
    $last=end($candidates);$cursor=$last['sort_key'];$lastId=(int)$last['id'];shuffle($candidates);
    foreach($candidates as $candidate){
     // Classify the saved template, before inserting event/movie names or dates.
     $group=preg_match('/[^\x00-\x7F]/',$candidate['template_text'])===1?0:1;
     if($picked[$group]>=$targets[$group])continue;
     $title=self::render($candidate['template_text'],$event,$date,$eventType);
     $reserve->execute([$keyword,$candidate['template_hash'],hash('sha256',self::normalized($title)),$job]);
     if($reserve->rowCount()){$titles[]=$title;++$picked[$group];}
     if(count($titles)===$count)break;
    }
    if(count($candidates)<$limit)break;
   }
   if(count($titles)===$count)break;
  }
  if(count($titles)<$count)throw new InvalidArgumentException('Not enough unused titles for the selected mix ('.$unicodePercent.'% Unicode / '.(100-$unicodePercent).'% fresh). Still needed: '.($targets[0]-$picked[0]).' Unicode and '.($targets[1]-$picked[1]).' fresh titles. Add matching titles or lower the count. No titles from this attempt were consumed.');
  shuffle($titles);
  return $titles;
 }
 public function export():void{
  $this->db->beginTransaction();
  try{
   $info=$this->info(true);if(empty($info['id']))throw new InvalidArgumentException('No title templates saved yet.');
   header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="saved-title-templates.txt"');
   $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
   $q=$this->db->prepare('SELECT template_text FROM eg_title_entries WHERE bank_id=? ORDER BY id');$q->execute([$info['id']]);while($row=$q->fetch(PDO::FETCH_ASSOC))echo $row['template_text']."\n";$q->closeCursor();
   $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$this->db->commit();
  }catch(Throwable $e){$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
