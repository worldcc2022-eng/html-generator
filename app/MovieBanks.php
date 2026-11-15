<?php
declare(strict_types=1);
require_once __DIR__.'/KeywordTemplate.php';
final class MovieTitleBank {
 public function __construct(private PDO $db){}
 public function ensureSchema(string $storageDir=''):void{
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_movie_title_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bank_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_text TEXT NOT NULL, sort_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, UNIQUE KEY bank_template (bank_id,template_hash), KEY random_pick (bank_id,sort_key,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_movie_title_used (keyword_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, title_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (keyword_hash,template_hash), UNIQUE KEY keyword_title (keyword_hash,title_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  
 }
 private static function normalized(string $v):string{return mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)),'UTF-8');}
 public static function keywordHash(string $event):string{return hash('sha256',self::normalized($event));}
 public static function templateHash(string $template):string{return hash('sha256',self::normalized($template));}
 public static function render(string $template,string $event,?DateTimeImmutable $date,string $eventType='Movie'):string{
  $template=KeywordTemplate::withoutDuplicates($template,$event);
  return strtr($template,['{movie_name}'=>$event,'{year}'=>$date?->format('Y')??'']);
 }
 public static function validateTemplate(string $line,int $number):string{
  $line=trim($line);if(preg_match('/\{(?!movie_name\}|year\})[^}]*\}/u',$line))throw new InvalidArgumentException('Movie titles support only {movie_name} and {year}, without dates.');if(!mb_check_encoding($line,'UTF-8')||mb_strlen($line)>2048||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$line))throw new InvalidArgumentException('Invalid UTF-8 title or title over 2,048 characters at line '.$number.'.');
  if(!str_contains($line,'{movie_name}'))throw new InvalidArgumentException('Line '.$number.' must contain {movie_name}. One title template per line.');
  return $line;
 }
 public function info(bool $lock=false):array{
  $q=$this->db->prepare('SELECT value FROM eg_settings WHERE name=?'.($lock?' FOR UPDATE':''));$q->execute(['movie_title_bank']);return json_decode($q->fetchColumn()?:'{}',true)?:[];
 }
 public function import($stream,string $filename,string $mode,string $lockPath):array{
  if(!is_resource($stream)||!in_array($mode,['append','replace'],true))throw new InvalidArgumentException('Choose a TXT file or paste one title per line.');
  $guard=fopen($lockPath,'c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Another title import is running. Try again shortly.');
  $bank=bin2hex(random_bytes(16));$published=false;$lineNumber=0;$provided=0;$values=[];$before=0;
  try{
   $old=$this->info();
   if($mode==='append'&&!empty($old['id'])){
    $q=$this->db->prepare('INSERT INTO eg_movie_title_entries (bank_id,template_hash,template_text,sort_key) SELECT ?,template_hash,template_text,sort_key FROM eg_movie_title_entries WHERE bank_id=?');$q->execute([$bank,$old['id']]);$before=(int)$q->rowCount();
   }
   $flush=function()use(&$values,$bank){
    if(!$values)return;
    $q=$this->db->prepare('INSERT IGNORE INTO eg_movie_title_entries (bank_id,template_hash,template_text,sort_key) VALUES '.implode(',',array_fill(0,count($values),'(?,?,?,?)')));$args=[];
    foreach($values as $line)array_push($args,$bank,self::templateHash($line),$line,bin2hex(random_bytes(16)));
    $q->execute($args);$values=[];
   };
   while(($raw=fgets($stream,65537))!==false){
    ++$lineNumber;if($lineNumber===1)$raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
    if(trim($raw)==='')continue;
    $values[]=self::validateTemplate($raw,$lineNumber);++$provided;if(count($values)===300)$flush();
   }
   $flush();if(!$provided)throw new InvalidArgumentException('No title templates found. Paste titles or upload a UTF-8 TXT file.');
   $q=$this->db->prepare('SELECT COUNT(*) FROM eg_movie_title_entries WHERE bank_id=?');$q->execute([$bank]);$count=(int)$q->fetchColumn();
   $info=['id'=>$bank,'count'=>$count,'filename'=>mb_substr($filename,0,255),'saved_at'=>gmdate('c'),'added'=>$count-$before];
   $this->db->beginTransaction();
   $q=$this->db->prepare('INSERT INTO eg_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$q->execute(['movie_title_bank',json_encode($info,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$this->db->commit();$published=true;
   // Active jobs already snapshot their titles. History stays separate and is never reset.
   $q=$this->db->prepare('DELETE FROM eg_movie_title_entries WHERE bank_id<>?');$q->execute([$bank]);
   return $info;
  }finally{
   if($this->db->inTransaction())$this->db->rollBack();
   if(!$published){$q=$this->db->prepare('DELETE FROM eg_movie_title_entries WHERE bank_id=?');$q->execute([$bank]);}
   flock($guard,LOCK_UN);fclose($guard);
  }
 }
 public function pick(string $event,?DateTimeImmutable $date,int $count,string $job,string $eventType='Movie',int $unicodePercent=50):array{
  if(!$this->db->inTransaction())throw new LogicException('Title reservations must be transactional.');
  $info=$this->info(true);if(empty($info['id']))throw new InvalidArgumentException('Save your title templates on the Movies page first. Each line must contain {movie_name}.');
  $keyword=self::keywordHash($event.' '.($date?->format('Y')??''));$pivot=bin2hex(random_bytes(16));$titles=[];$limit=min(2000,max(100,$count*2));
  $date=$date??new DateTimeImmutable('now',new DateTimeZone('-05:00'));
  if($unicodePercent<0||$unicodePercent>100)throw new InvalidArgumentException('Unicode % must be between 0 and 100.');
  $unicodeCount=intdiv($count*$unicodePercent+50,100);$targets=[$unicodeCount,$count-$unicodeCount];$picked=[0,0];
  $reserve=$this->db->prepare('INSERT IGNORE INTO eg_movie_title_used (keyword_hash,template_hash,title_hash,job_id) VALUES (?,?,?,?)');
  foreach(['>=','<'] as $side){
   $cursor=null;$lastId=0;
   while(count($titles)<$count){
    $where='e.bank_id=? AND e.sort_key '.$side.' ?';$args=[$info['id'],$pivot];
    if($cursor!==null){$where.=' AND (e.sort_key>? OR (e.sort_key=? AND e.id>?))';array_push($args,$cursor,$cursor,$lastId);}
    $where.=' AND NOT EXISTS (SELECT 1 FROM eg_movie_title_used u WHERE u.keyword_hash=? AND u.template_hash=e.template_hash)';$args[]=$keyword;
    $q=$this->db->prepare('SELECT e.id,e.template_hash,e.template_text,e.sort_key FROM eg_movie_title_entries e WHERE '.$where.' ORDER BY e.sort_key,e.id LIMIT '.$limit);$q->execute($args);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
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
   $q=$this->db->prepare('SELECT template_text FROM eg_movie_title_entries WHERE bank_id=? ORDER BY id');$q->execute([$info['id']]);while($row=$q->fetch(PDO::FETCH_ASSOC))echo $row['template_text']."\n";$q->closeCursor();
   $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$this->db->commit();
  }catch(Throwable $e){$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}

final class MovieIdBank {
 public function __construct(private PDO $db){}
 public function ensureSchema(string $storageDir=''):void{
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_movie_id_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bank_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_text TEXT NOT NULL, sort_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, UNIQUE KEY bank_template (bank_id,template_hash), KEY random_pick (bank_id,sort_key,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_movie_id_used (keyword_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (keyword_hash,template_hash), UNIQUE KEY keyword_id (keyword_hash,id_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
 }
 private static function normalized(string $v):string{return mb_strtolower(trim(preg_replace('/\s+/u',' ',$v)),'UTF-8');}
 public static function keywordHash(string $event):string{return hash('sha256',self::normalized($event));}
 public static function templateHash(string $template):string{return hash('sha256',self::normalized($template));}
 public static function render(string $template,string $event,?DateTimeImmutable $date,string $eventType='Movie'):string{
  $template=KeywordTemplate::withoutDuplicates($template,$event,true);
  $slug=EventGenerator::slug($event);
  if($slug==='')throw new InvalidArgumentException('The movie name must contain letters or numbers usable in an ID.');
  $id=preg_replace('/-+/','-',str_replace(['{movie_name}','{year}','_'],[$slug,$date?->format('Y')??'','-'],$template));
  if(strlen($id)>240||!preg_match('/^[a-z0-9][a-z0-9-]*$/D',$id))throw new InvalidArgumentException('Generated ID must be 1–240 lowercase letters, numbers, dashes. Shorten the movie name or ID template.');
  return $id;
 }
 public static function validateTemplate(string $line,int $number):string{
  $line=strtolower(trim($line));
  $line=str_replace('{movie_name}','{moviename}',$line);
  $line=preg_replace('/-+/','-',str_replace('_','-',$line));
  $line=str_replace('{moviename}','{movie_name}',$line);
  if(!str_contains($line,'{movie_name}')||strlen($line)>240||!preg_match('/^[a-z0-9][a-z0-9-]*$/D',str_replace(['{movie_name}','{year}'],['movie-name','2026'],$line)))throw new InvalidArgumentException('Invalid ID template at line '.$number.'. Use {movie_name}, letters, numbers, dashes only; maximum 240 characters. Do not include .html.');
  return $line;
 }
 public function info(bool $lock=false):array{
  $q=$this->db->prepare('SELECT value FROM eg_settings WHERE name=?'.($lock?' FOR UPDATE':''));$q->execute(['movie_id_bank']);return json_decode($q->fetchColumn()?:'{}',true)?:[];
 }
 public function import($stream,string $filename,string $mode,string $lockPath):array{
  if(!is_resource($stream)||!in_array($mode,['append','replace'],true))throw new InvalidArgumentException('Choose a TXT file or paste one ID per line.');
  $guard=fopen($lockPath,'c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new RuntimeException('Another ID import is running. Try again shortly.');
  $bank=bin2hex(random_bytes(16));$published=false;$lineNumber=0;$provided=0;$values=[];$before=0;
  try{
   $old=$this->info();
   if($mode==='append'&&!empty($old['id'])){
    $q=$this->db->prepare('INSERT INTO eg_movie_id_entries (bank_id,template_hash,template_text,sort_key) SELECT ?,template_hash,template_text,sort_key FROM eg_movie_id_entries WHERE bank_id=?');$q->execute([$bank,$old['id']]);$before=(int)$q->rowCount();
   }
   $flush=function()use(&$values,$bank){
    if(!$values)return;
    $q=$this->db->prepare('INSERT IGNORE INTO eg_movie_id_entries (bank_id,template_hash,template_text,sort_key) VALUES '.implode(',',array_fill(0,count($values),'(?,?,?,?)')));$args=[];
    foreach($values as $line)array_push($args,$bank,self::templateHash($line),$line,bin2hex(random_bytes(16)));
    $q->execute($args);$values=[];
   };
   while(($raw=fgets($stream,65537))!==false){
    ++$lineNumber;if($lineNumber===1)$raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
    if(trim($raw)==='')continue;
    $values[]=self::validateTemplate($raw,$lineNumber);++$provided;if(count($values)===300)$flush();
   }
   $flush();if(!$provided)throw new InvalidArgumentException('No ID templates found. Paste IDs or upload a UTF-8 TXT file.');
   $q=$this->db->prepare('SELECT COUNT(*) FROM eg_movie_id_entries WHERE bank_id=?');$q->execute([$bank]);$count=(int)$q->fetchColumn();
   $info=['id'=>$bank,'count'=>$count,'filename'=>mb_substr($filename,0,255),'saved_at'=>gmdate('c'),'added'=>$count-$before];
   $this->db->beginTransaction();
   $q=$this->db->prepare('INSERT INTO eg_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$q->execute(['movie_id_bank',json_encode($info,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$this->db->commit();$published=true;
   // Active jobs already snapshot their IDs. History stays separate and is never reset.
   $q=$this->db->prepare('DELETE FROM eg_movie_id_entries WHERE bank_id<>?');$q->execute([$bank]);
   return $info;
  }finally{
   if($this->db->inTransaction())$this->db->rollBack();
   if(!$published){$q=$this->db->prepare('DELETE FROM eg_movie_id_entries WHERE bank_id=?');$q->execute([$bank]);}
   flock($guard,LOCK_UN);fclose($guard);
  }
 }
 public function pick(string $event,?DateTimeImmutable $date,int $count,string $job,string $eventType='Movie'):array{
  if(!$this->db->inTransaction())throw new LogicException('ID reservations must be transactional.');
  $info=$this->info(true);if(empty($info['id']))throw new InvalidArgumentException('Save your ID templates on the Movies page first. Each line must contain {movie_name}.');
  $keyword=self::keywordHash($event.' '.($date?->format('Y')??''));$pivot=bin2hex(random_bytes(16));$titles=[];$limit=min(2000,max(100,$count*2));
  $reserve=$this->db->prepare('INSERT IGNORE INTO eg_movie_id_used (keyword_hash,template_hash,id_hash,job_id) VALUES (?,?,?,?)');
  foreach(['>=','<'] as $side){
   $cursor=null;$lastId=0;
   while(count($titles)<$count){
    $where='e.bank_id=? AND e.sort_key '.$side.' ?';$args=[$info['id'],$pivot];
    if($cursor!==null){$where.=' AND (e.sort_key>? OR (e.sort_key=? AND e.id>?))';array_push($args,$cursor,$cursor,$lastId);}
    $where.=' AND NOT EXISTS (SELECT 1 FROM eg_movie_id_used u WHERE u.keyword_hash=? AND u.template_hash=e.template_hash)';$args[]=$keyword;
    $q=$this->db->prepare('SELECT e.id,e.template_hash,e.template_text,e.sort_key FROM eg_movie_id_entries e WHERE '.$where.' ORDER BY e.sort_key,e.id LIMIT '.$limit);$q->execute($args);$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$candidates)break;
    // The indexed random starting point keeps large banks fast; shuffle the sampled window too.
    $last=end($candidates);$cursor=$last['sort_key'];$lastId=(int)$last['id'];shuffle($candidates);
    foreach($candidates as $candidate){
     $title=self::render($candidate['template_text'],$event,$date,$eventType);
     $reserve->execute([$keyword,$candidate['template_hash'],hash('sha256',self::normalized($title)),$job]);
     if($reserve->rowCount())$titles[]=$title;
     if(count($titles)===$count)break;
    }
    if(count($candidates)<$limit)break;
   }
   if(count($titles)===$count)break;
  }
  if(count($titles)<$count)throw new InvalidArgumentException('Only '.count($titles).' unused IDs remain for this keyword; you requested '.$count.'. Add new templates or lower the count. No IDs from this attempt were consumed.');
  return $titles;
 }
 public function export():void{
  $this->db->beginTransaction();
  try{
   $info=$this->info(true);if(empty($info['id']))throw new InvalidArgumentException('No ID templates saved yet.');
   header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="saved-id-templates.txt"');
   $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
   $q=$this->db->prepare('SELECT template_text FROM eg_movie_id_entries WHERE bank_id=? ORDER BY id');$q->execute([$info['id']]);while($row=$q->fetch(PDO::FETCH_ASSOC))echo $row['template_text']."\n";$q->closeCursor();
   $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$this->db->commit();
  }catch(Throwable $e){$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
