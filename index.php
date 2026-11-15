<?php
declare(strict_types=1);
define('EVENTGEN',true);require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/ServerUpload.php';require_once __DIR__.'/app/ConnGenerator.php';
$path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/','/')?:'/';
if(!is_file(__DIR__.'/config.php')){header('Location: /install');exit;}
try{
 if($path==='/login'){
  $error='';
  if($_SERVER['REQUEST_METHOD']==='POST'){
   try{
    verifyCsrf();$ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',config()['secret']);$now=time();
    db()->beginTransaction();
    db()->prepare('INSERT IGNORE INTO eg_login_attempts (ip_hash) VALUES (?)')->execute([$ip]);
    $q=db()->prepare('SELECT * FROM eg_login_attempts WHERE ip_hash=? FOR UPDATE');$q->execute([$ip]);$a=$q->fetch();
    if((int)$a['blocked_until']>$now){db()->commit();throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.');}
    $tries=($now-(int)$a['touched']>900?0:(int)$a['attempts'])+1;
    db()->prepare('UPDATE eg_login_attempts SET attempts=?, blocked_until=?, touched=? WHERE ip_hash=?')->execute([$tries,$tries>=10?$now+900:0,$now,$ip]);db()->commit();
    $q=db()->prepare('SELECT * FROM eg_users WHERE username=?');$q->execute([substr(trim($_POST['username']??''),0,80)]);$u=$q->fetch();
    $hash=$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=password_verify($_POST['password']??'',$hash);
    if(!$u||!$valid)throw new RuntimeException('Incorrect username or password.');
    db()->prepare('DELETE FROM eg_login_attempts WHERE ip_hash=?')->execute([$ip]);
    $_SESSION=[];session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['last_active']=time();header('Location: /');exit;
   }catch(InvalidArgumentException|RuntimeException $e){if(db()->inTransaction())db()->rollBack();$error=$e instanceof PDOException?'Sign-in unavailable. Check database configuration.':$e->getMessage();}
  }
  $body='<section class="panel"><div class="eyebrow">WELCOME BACK</div><h1>Sign in.<br>Create something.</h1><p>Your event files, ready in one workspace.</p>';
  if(isset($_GET['installed']))$body.=message('Installation complete. Sign in, then delete install.php from your server.','success');
  if($error)$body.=message($error);
  $body.='<form method="post" class="stack" data-busy="Signing In…">'.tokenField().input('Username','username','text',$_POST['username']??'').'<label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Sign In</button></form></section>';
  page('Login',$body,true);exit;
 }
 requireLogin();
 if($path==='/logout'&&$_SERVER['REQUEST_METHOD']==='POST'){verifyCsrf();$_SESSION=[];session_destroy();header('Location: /login');exit;}
 if(str_starts_with($path,'/api/')){
  if($_SERVER['REQUEST_METHOD']!=='POST')jsonOut(['error'=>'Use POST.'],405);
  $postLimit=ini_get('post_max_size');$bytes=(int)$postLimit;$unit=strtolower(substr(trim($postLimit),-1));$bytes*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
  if($bytes>0&&(int)($_SERVER['CONTENT_LENGTH']??0)>$bytes)jsonOut(['error'=>'Upload exceeds PHP post_max_size. Increase post_max_size and upload_max_filesize in cPanel PHP settings, then retry.'],413);
  verifyCsrf();
  // Every API uses the authenticated session snapshot below. Release its file
  // lock before any database, import, OpenAI or Git work: another request
  // cannot even reach its own handler while this session remains locked.
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  $movieMode=($_POST['generator']??'')==='movie';$connMode=($_POST['generator']??'')==='conn';
  if($movieMode)require_once __DIR__.'/app/MovieBanks.php';
  if($path==='/api/conn-template'){
   $value=$_FILES['xml_template']??null;if(!$value||$value['error']!==UPLOAD_ERR_OK||$value['size']>3*1024*1024)throw new InvalidArgumentException('Choose an XML template up to 3 MB.');
   $xml=ConnGenerator::template((string)file_get_contents($value['tmp_name']));saveSetting('conn_xml_template_'.(int)$_SESSION['user_id'],$xml);jsonOut(['saved'=>true]);
  }
  if($path==='/api/xml-url'){
   $value=$_POST['xml_path']??'';if(!is_string($value))throw new InvalidArgumentException('Enter a valid XML URL.');
   jsonOut(['xml_url'=>saveXmlUrl((int)$_SESSION['user_id'],$value,is_string($_POST['xml_target']??null)?$_POST['xml_target']:'github')]);
  }
  if(str_starts_with($path,'/api/indexing-')){require_once __DIR__.'/app/IndexingPage.php';indexingApi($path);}
  if($path==='/api/server-settings')serverUploadSettingsApi();
  if($path==='/api/server-upload')serverUploadApi();
  if($path==='/api/server-upload-status')serverUploadStatusApi();
  if($path==='/api/github-upload'){require_once __DIR__.'/app/GitHubUpload.php';githubUploadApi();}
  if($path==='/api/github-connect'){require_once __DIR__.'/app/GitHubSettings.php';githubConnectApi();}
  if($path==='/api/history')jsonOut(['html'=>recentBatchRows((int)$_SESSION['user_id'])]);
  if($path==='/api/history-clear'){
   $q=db()->prepare("INSERT IGNORE INTO eg_settings (name,value) SELECT CONCAT('hidden_job_',id),? FROM eg_jobs WHERE user_id=?");$q->execute([gmdate('Y-m-d H:i:s'),$_SESSION['user_id']]);
   jsonOut(['cleared'=>true]);
  }
  if($path==='/api/title-bank'){
   $bank=$movieMode?new MovieTitleBank(db()):new TitleBank(db());$bank->ensureSchema($movieMode?'':ROOT.'/storage');
   $action=$_POST['action']??'';
   if(in_array($action,['begin','chunk','finish','cancel'],true)){
    if($action==='begin'){
     $size=filter_var($_POST['size']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>128*1024*1024]]);$mode=$_POST['title_mode']??'append';
     if($size===false||!in_array($mode,['append','replace'],true))throw new InvalidArgumentException('Choose a title list from 1 byte to 128 MB.');
     $id=bin2hex(random_bytes(16));$folder=ROOT.'/storage/'.($movieMode?'movie-':'').'title-upload-'.(int)$_SESSION['user_id'].'-'.$id;
     if(!mkdir($folder,0700))throw new RuntimeException('Unable to stage title upload. Check storage permissions.');
     writeFileAtomic($folder.'/meta.json',json_encode(['size'=>$size,'received'=>0,'next'=>0,'filename'=>mb_substr(basename(str_replace('\\','/',$_POST['filename']??'titles.txt')),0,255),'mode'=>$mode]));
     jsonOut(['upload_id'=>$id]);
    }
    $id=$_POST['upload_id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))throw new InvalidArgumentException('Invalid title upload.');
    $folder=ROOT.'/storage/'.($movieMode?'movie-':'').'title-upload-'.(int)$_SESSION['user_id'].'-'.$id;$metaPath=$folder.'/meta.json';
    if(!is_file($metaPath))throw new InvalidArgumentException('Title upload expired. Select the file and try again.');
    $meta=json_decode(file_get_contents($metaPath),true,512,JSON_THROW_ON_ERROR);
    if($action==='chunk'){
     $chunk=$_FILES['chunk']??null;$index=filter_var($_POST['index']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
     if(!$chunk||$chunk['error']!==UPLOAD_ERR_OK||$chunk['size']<1||$chunk['size']>524288||$index===false||$index!==$meta['next'])throw new InvalidArgumentException('Title upload chunk is missing or out of order. Select the file and retry.');
     if($meta['received']+$chunk['size']>$meta['size'])throw new InvalidArgumentException('Title upload exceeds its declared size.');
     $target=fopen($folder.'/titles.txt','c+b');$input=fopen($chunk['tmp_name'],'rb');
     if(!$target||!$input)throw new RuntimeException('Unable to write title upload.');
     try{if(!ftruncate($target,$meta['received'])||fseek($target,$meta['received'])!==0||stream_copy_to_stream($input,$target)!==$chunk['size'])throw new RuntimeException('Could not save title upload chunk. Check free disk space.');}finally{fclose($target);fclose($input);}
     $meta['received']+=$chunk['size'];$meta['next']++;writeFileAtomic($metaPath,json_encode($meta));jsonOut(['received'=>$meta['received']]);
    }
    $source=null;
    try{
     if($action==='cancel'){foreach(['titles.txt','meta.json','meta.json.tmp'] as $file)@unlink($folder.'/'.$file);@rmdir($folder);jsonOut(['cancelled'=>true]);}
     if($meta['received']!==$meta['size']||!is_file($folder.'/titles.txt'))throw new InvalidArgumentException('Title upload is incomplete. Try uploading again.');
     @set_time_limit(180);$source=fopen($folder.'/titles.txt','rb');$info=$bank->import($source,$meta['filename'],$meta['mode'],ROOT.'/storage/'.($movieMode?'movie-':'').'title-bank-import.lock');
    }finally{if(is_resource($source))fclose($source);foreach(['titles.txt','meta.json','meta.json.tmp'] as $file)@unlink($folder.'/'.$file);@rmdir($folder);}
    jsonOut(['count'=>$info['count'],'added'=>$info['added'],'filename'=>$info['filename'],'saved_at'=>(new DateTimeImmutable($info['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)']);
   }
   if($action!=='import')throw new InvalidArgumentException('Invalid title bank action.');
   @set_time_limit(180);$source=null;
   try{
    $file=$_FILES['title_file']??null;
    if($file&&$file['error']!==UPLOAD_ERR_NO_FILE){
     if($file['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('TXT upload failed or exceeds the server upload_max_filesize. Increase PHP upload limits in cPanel and try again.');
     if(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='txt'||$file['size']>128*1024*1024)throw new InvalidArgumentException('Upload a UTF-8 TXT title list up to 128 MB.');
     $source=fopen($file['tmp_name'],'rb');$filename=basename(str_replace('\\','/',$file['name']));
    }else{
     $text=$_POST['title_templates']??'';if(!is_string($text)||strlen($text)>128*1024*1024)throw new InvalidArgumentException('Title list exceeds 128 MB. Use a smaller TXT file.');
     $source=fopen('php://temp/maxmemory:2097152','w+b');fwrite($source,$text);rewind($source);$filename='Pasted titles';
    }
    $info=$bank->import($source,$filename,$_POST['title_mode']??'append',ROOT.'/storage/'.($movieMode?'movie-':'').'title-bank-import.lock');
   }finally{if(is_resource($source))fclose($source);}
   jsonOut(['count'=>$info['count'],'added'=>$info['added'],'filename'=>$info['filename'],'saved_at'=>(new DateTimeImmutable($info['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)']);
  }
  if($path==='/api/id-bank'){
   $bank=$movieMode?new MovieIdBank(db()):new IdBank(db());$bank->ensureSchema(ROOT.'/storage');
   $action=$_POST['action']??'';
   if(in_array($action,['begin','chunk','finish','cancel'],true)){
    if($action==='begin'){
     $size=filter_var($_POST['size']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>128*1024*1024]]);$mode=$_POST['id_mode']??'append';
     if($size===false||!in_array($mode,['append','replace'],true))throw new InvalidArgumentException('Choose a ID list from 1 byte to 128 MB.');
     $id=bin2hex(random_bytes(16));$folder=ROOT.'/storage/'.($movieMode?'movie-':'').'id-upload-'.(int)$_SESSION['user_id'].'-'.$id;
     if(!mkdir($folder,0700))throw new RuntimeException('Unable to stage ID upload. Check storage permissions.');
     writeFileAtomic($folder.'/meta.json',json_encode(['size'=>$size,'received'=>0,'next'=>0,'filename'=>mb_substr(basename(str_replace('\\','/',$_POST['filename']??'ids.txt')),0,255),'mode'=>$mode]));
     jsonOut(['upload_id'=>$id]);
    }
    $id=$_POST['upload_id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))throw new InvalidArgumentException('Invalid ID upload.');
    $folder=ROOT.'/storage/'.($movieMode?'movie-':'').'id-upload-'.(int)$_SESSION['user_id'].'-'.$id;$metaPath=$folder.'/meta.json';
    if(!is_file($metaPath))throw new InvalidArgumentException('ID upload expired. Select the file and try again.');
    $meta=json_decode(file_get_contents($metaPath),true,512,JSON_THROW_ON_ERROR);
    if($action==='chunk'){
     $chunk=$_FILES['chunk']??null;$index=filter_var($_POST['index']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
     if(!$chunk||$chunk['error']!==UPLOAD_ERR_OK||$chunk['size']<1||$chunk['size']>524288||$index===false||$index!==$meta['next'])throw new InvalidArgumentException('ID upload chunk is missing or out of order. Select the file and retry.');
     if($meta['received']+$chunk['size']>$meta['size'])throw new InvalidArgumentException('ID upload exceeds its declared size.');
     $target=fopen($folder.'/ids.txt','c+b');$input=fopen($chunk['tmp_name'],'rb');
     if(!$target||!$input)throw new RuntimeException('Unable to write ID upload.');
     try{if(!ftruncate($target,$meta['received'])||fseek($target,$meta['received'])!==0||stream_copy_to_stream($input,$target)!==$chunk['size'])throw new RuntimeException('Could not save ID upload chunk. Check free disk space.');}finally{fclose($target);fclose($input);}
     $meta['received']+=$chunk['size'];$meta['next']++;writeFileAtomic($metaPath,json_encode($meta));jsonOut(['received'=>$meta['received']]);
    }
    $source=null;
    try{
     if($action==='cancel'){foreach(['ids.txt','meta.json','meta.json.tmp'] as $file)@unlink($folder.'/'.$file);@rmdir($folder);jsonOut(['cancelled'=>true]);}
     if($meta['received']!==$meta['size']||!is_file($folder.'/ids.txt'))throw new InvalidArgumentException('ID upload is incomplete. Try uploading again.');
     @set_time_limit(180);$source=fopen($folder.'/ids.txt','rb');$info=$bank->import($source,$meta['filename'],$meta['mode'],ROOT.'/storage/'.($movieMode?'movie-':'').'id-bank-import.lock');
    }finally{if(is_resource($source))fclose($source);foreach(['ids.txt','meta.json','meta.json.tmp'] as $file)@unlink($folder.'/'.$file);@rmdir($folder);}
    jsonOut(['count'=>$info['count'],'added'=>$info['added'],'filename'=>$info['filename'],'saved_at'=>(new DateTimeImmutable($info['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)']);
   }
   if($action!=='import')throw new InvalidArgumentException('Invalid ID bank action.');
   @set_time_limit(180);$source=null;
   try{
    $file=$_FILES['id_file']??null;
    if($file&&$file['error']!==UPLOAD_ERR_NO_FILE){
     if($file['error']!==UPLOAD_ERR_OK)throw new InvalidArgumentException('TXT upload failed or exceeds the server upload_max_filesize. Increase PHP upload limits in cPanel and try again.');
     if(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='txt'||$file['size']>128*1024*1024)throw new InvalidArgumentException('Upload a UTF-8 TXT ID list up to 128 MB.');
     $source=fopen($file['tmp_name'],'rb');$filename=basename(str_replace('\\','/',$file['name']));
    }else{
     $text=$_POST['id_templates']??'';if(!is_string($text)||strlen($text)>128*1024*1024)throw new InvalidArgumentException('Title list exceeds 128 MB. Use a smaller TXT file.');
     $source=fopen('php://temp/maxmemory:2097152','w+b');fwrite($source,$text);rewind($source);$filename='Pasted IDs';
    }
    $info=$bank->import($source,$filename,$_POST['id_mode']??'append',ROOT.'/storage/'.($movieMode?'movie-':'').'id-bank-import.lock');
   }finally{if(is_resource($source))fclose($source);}
   jsonOut(['count'=>$info['count'],'added'=>$info['added'],'filename'=>$info['filename'],'saved_at'=>(new DateTimeImmutable($info['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)']);
  }
  if($path==='/api/description'){
   $event=trim($_POST['event']??'');if(mb_strlen($event)>180||strlen($event)<3||preg_match('/[<>\x00-\x1f]/',$event))throw new InvalidArgumentException('Enter a valid event name, 3–180 characters.');
   $date=EventGenerator::optionalDate($_POST['date']??'',$_POST['date_order']??'auto',$event)?->format('Y-m-d')??'';
   $context=hash('sha256',json_encode([$event,$date]));$name='description_review_'.(int)$_SESSION['user_id'];
   $review=json_decode(setting($name,'{}'),true)?:[];$action=$_POST['action']??'status';
   if($action==='generate'){
    $key=setting('api_key');if($key==='')throw new InvalidArgumentException('Add your OpenAI API key in Settings first.');
    require_once __DIR__.'/app/OpenAI.php';@set_time_limit(120);
    $previous=($review['context']??'')===$context?($review['text']??null):null;
    $research=OpenAI::researchedDescription(cryptKey($key,true),setting('model','gpt-4.1-mini'),$event,$date,$previous);$description=$research['description'];
    if($previous!==null&&$description===$previous)throw new RuntimeException('OpenAI repeated the same description. Regenerate to request a new version.');
    $review=['context'=>$context,'event'=>$event,'date'=>$date,'text'=>$description,'revision'=>bin2hex(random_bytes(16)),'approved'=>false,'sources'=>$research['sources'],'viewing_details_found'=>$research['viewing_details_found'],'searched_at'=>$research['searched_at'],'start_date'=>$research['start_date'],'end_date'=>$research['end_date'],'event_start_at'=>$research['event_start_at']??null,'schema_time_approved'=>false];
    saveSetting($name,json_encode($review,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
   }elseif(in_array($action,['approve','approve_time','use_generation_time','manual_time'],true)){
    db()->beginTransaction();
    try{
     $query=db()->prepare('SELECT value FROM eg_settings WHERE name=? FOR UPDATE');$query->execute([$name]);$review=json_decode($query->fetchColumn()?:'{}',true)?:[];
     if(($review['context']??'')!==$context||!is_string($_POST['revision']??null)||!hash_equals($review['revision']??'',$_POST['revision']))throw new InvalidArgumentException('Description changed or belongs to another event/date. Load the latest description and review it again.');
     if($action==='approve'){EventGenerator::validateDescription($review['text']);$review['approved']=true;}elseif($action==='manual_time'){$review=SchemaTime::manual($review,$context,(string)$_POST['revision'],is_string($_POST['schema_manual_date']??null)?$_POST['schema_manual_date']:'',is_string($_POST['schema_manual_time']??null)?$_POST['schema_manual_time']:'');}else{$review=SchemaTime::decide($review,$context,(string)$_POST['revision'],$action==='approve_time');}saveSetting($name,json_encode($review,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
   }elseif($action!=='status')throw new InvalidArgumentException('Invalid description action.');
   if(($review['context']??'')!==$context)jsonOut(['description'=>null,'approved'=>false]);
   jsonOut(['description'=>$review['text'],'revision'=>$review['revision'],'approved'=>(bool)$review['approved'],'words'=>EventGenerator::descriptionWords($review['text']),'event'=>$event,'date'=>$date,'sources'=>$review['sources']??[],'viewing_details_found'=>$review['viewing_details_found']??null,'searched_at'=>$review['searched_at']??null,'start_date'=>$review['start_date']??null,'end_date'=>$review['end_date']??null,'time_review'=>SchemaTime::view($review)]);
  }
  if($path==='/api/start'){
   [$unicodePercent,$freshPercent]=TitleBank::percentages($_POST['unicode_percent']??50,$_POST['fresh_percent']??50);
   $keywords=$movieMode?[movieName($_POST['event']??'')]:EventKeywords::parse($_POST['event']??'',$_POST['event_keywords']??[]);$event=$keywords[0];$randomizeKeywords=!$movieMode&&($_POST['randomize_keywords']??'')==='1';
   $movieYear=$movieMode?movieYear($_POST['year']??''):null;$bankDate=$movieMode?new DateTimeImmutable($movieYear.'-01-01'):null;
   $date=$movieMode?null:EventGenerator::optionalDate($_POST['date']??'',$_POST['date_order']??'auto',$event);$base=$connMode?'':EventGenerator::xmlBase64(savedXmlForGeneration((int)$_SESSION['user_id'],($_POST['xml_target']??'github')==='server'?($_POST['server_xml_path']??''):($_POST['xml_path']??''),is_string($_POST['xml_target']??null)?$_POST['xml_target']:'github'));$total=filter_var($_POST['count']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);if($total===false)throw new InvalidArgumentException('Enter a whole-number count from 1 to 1000.');
   $conn=null;if($connMode){$connHosting=serverUploadSaved()['public_url']??'';if($connHosting==='')throw new InvalidArgumentException('Save the server Public URL in Settings before generating CONN files.');$connBase=ConnGenerator::baseUrl($connHosting);$labHost=ConnGenerator::LAB_HOST;$outputEndpoints=ConnGenerator::labEndpoints(is_string($_POST['url_domains']??null)?$_POST['url_domains']:'',$connBase);$conn=['base_url'=>$connBase,'lab_host'=>$labHost,'output_endpoints'=>$outputEndpoints,'output_assignments'=>ConnGenerator::assignEndpoints($outputEndpoints,$total,($_POST['randomize_domains']??(count($outputEndpoints)>1?'1':''))==='1'),'xml_template'=>ConnGenerator::template(setting('conn_xml_template_'.(int)$_SESSION['user_id'])),'uuids'=>array_map(fn()=> (string)random_int(1000000000000,9999999999999),range(1,$total)),'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('-05:00')))->format(DATE_ATOM)];}
   $keywordAllocation=EventKeywords::allocate($keywords,$total);$batchLabel=count($keywords)>1?mb_substr($event,0,150).' + '.(count($keywords)-1).' more':$event;
   $urlDomains=EventGenerator::urlDomains($_POST['url_domains']??$_POST['url_domain']??'');$urlDomain=$urlDomains[0]??'';$randomizeDomains=($_POST['randomize_domains']??(!$movieMode&&count($urlDomains)>1?'1':''))==='1';$spreadSchemaTime=!$movieMode&&($_POST['spread_schema_time']??'')==='1';$spreadSeconds=$spreadSchemaTime?SchemaTime::spreadSeconds($_POST['spread_hours']??'1',$total):3600;$broadcastEnabled=!$movieMode&&($_POST['broadcast_enabled']??'1')==='1';$broadcastPercent=$broadcastEnabled?BroadcastSchema::percentage($_POST['broadcast_percent']??'100'):0;$broadcastEnabled=$broadcastEnabled&&$broadcastPercent>0;
   $eventType=trim($_POST['event_type_custom']??'')?:trim($_POST['event_type']??'Event');if($eventType==='')$eventType='Event';
   if($eventType===''||!mb_check_encoding($eventType,'UTF-8')||mb_strlen($eventType)>100||preg_match('/[<>\x00-\x1f]/u',$eventType))throw new InvalidArgumentException('Select an event type or enter a manual event type (maximum 100 characters).');
   $broadcastMinutes=($movieMode||!$broadcastEnabled)?null:BroadcastDurations::forGeneration(BroadcastDurations::read(setting('broadcast_durations'),(int)setting('broadcast_durations_version','1')),(string)($_POST['event_type']??'Event'),$eventType,$_POST['broadcast_duration']??null,$_POST['broadcast_duration_unit']??null);
   $useAI=!$movieMode&&($_POST['use_openai']??'0')==='1';$description=null;$revision=null;$schemaTimestamp=null;
   if($useAI){
    $review=json_decode(setting('description_review_'.(int)$_SESSION['user_id'],'{}'),true)?:[];
    $context=hash('sha256',json_encode([$event,($date?->format('Y-m-d')??'')]));
    if(($review['context']??'')!==$context||empty($review['approved'])||!is_string($_POST['description_revision']??null)||!hash_equals($review['revision']??'',$_POST['description_revision']))throw new InvalidArgumentException('Generate and approve a description for this event and date before generating files.');
    $description=EventGenerator::validateDescription($review['text']);$revision=$review['revision'];$schemaTimestamp=SchemaTime::timestamp($review);if($date===null&&!empty($review['start_date']))$date=EventGenerator::date($review['start_date']);
   }
   if($spreadSchemaTime&&$schemaTimestamp===null)$schemaTimestamp=(new DateTimeImmutable('now',new DateTimeZone('-05:00')))->format(DATE_ATOM);
   if(array_key_exists('id_seeds',$_POST)){
    if(!is_string($_POST['id_seeds'])||strlen($_POST['id_seeds'])>256000)throw new InvalidArgumentException('Enter an ID list up to 256 KB.');
    $seeds=array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',$_POST['id_seeds'])?:[]),fn($seed)=>$seed!=='')));
   }else{$seeds=[];for($i=1;$i<=5;$i++)$seeds[]=trim($_POST['id_seed_'.$i]??'');}
   $stem=trim($_POST['id_prefix']??'')?:'video-'.EventGenerator::slug($event).'-live';
   $idSource=$connMode?'manual':($_POST['id_source']??'manual');
   if(!in_array($idSource,['manual','saved'],true))throw new InvalidArgumentException('Choose a valid ID source.');
   $idBank=$movieMode?new MovieIdBank(db()):new IdBank(db());if($idSource==='saved')$idBank->ensureSchema();
   $ids=$movieMode&&$idSource==='manual'?EventGenerator::ids(array_filter($seeds)?$seeds:[$stem],$total,$event):[];
   $eventUrl=trim($_POST['event_url']??'');
   if($eventUrl==='')throw new InvalidArgumentException('Enter the Event URL to use in the template redirect.');
   $template=file_get_contents(templatePath());if($template===false)throw new RuntimeException('Upload an HTML template in Settings.');
   $images=TemplateRenderer::imageUrls(setting('image_urls'));
   if(!$movieMode&&!$broadcastEnabled)$template=BroadcastSchema::disable($template);
   $template=PlaceholderRenderer::prepare($template,$eventUrl,$description,$images);
   $bank=$movieMode?new MovieTitleBank(db()):new TitleBank(db());$bank->ensureSchema($movieMode?'':ROOT.'/storage');$id=bin2hex(random_bytes(16));$dir=jobDir($id);$createdDir=false;
   db()->beginTransaction();
   try{
    if($movieMode){
     if($idSource==='saved')$ids=$idBank->pick($event,$bankDate,$total,$id);
     $titles=$bank->pick($event,$bankDate,$total,$id,$eventType,$unicodePercent);$rows=[];foreach($titles as $i=>$title)$rows[]=['id'=>$ids[$i],'title'=>$title];
    }else{
     $manualPrefix=trim($_POST['id_prefix']??'');
     $rows=EventKeywords::rows($keywordAllocation,$randomizeKeywords,
      fn($keyword,$count,$used)=>$connMode?ConnGenerator::ids($count,$used):($idSource==='saved'?$idBank->pick($keyword,$date,$count,$id,$eventType,$used):EventGenerator::ids(array_filter($seeds)?$seeds:[$manualPrefix?:'video-'.EventGenerator::slug($keyword).'-live'],$count,$keyword,$used)),
      fn($keyword,$count)=>$bank->pick($keyword,$date,$count,$id,$eventType,$unicodePercent));
    }
    if(!$movieMode)$rows=BroadcastSchema::distribute($rows,$broadcastPercent);
    if(!mkdir($dir,0700))throw new RuntimeException('Unable to create batch storage.');$createdDir=true;
    writeFileAtomic($dir.'/template.html',$template);writeFileAtomic($dir.'/state.json',json_encode(['generator'=>$connMode?'conn':($movieMode?'movie':'event'),'conn'=>$conn,'movie_year'=>$movieYear,'unicode_percent'=>$unicodePercent,'fresh_percent'=>$freshPercent,'event'=>$event,'event_keywords'=>$keywords,'keyword_allocation'=>$keywordAllocation,'randomize_keywords'=>$randomizeKeywords,'event_type'=>$eventType,'date'=>($date?->format('Y-m-d')??''),'base64'=>$base,'url_domain'=>$urlDomain,'url_domains'=>$urlDomains,'randomize_domains'=>$randomizeDomains,'render_options'=>['broadcast_percent'=>$movieMode?null:$broadcastPercent,'broadcast_enabled'=>$movieMode?true:$broadcastEnabled,'spread_seconds'=>$spreadSeconds,'spread_schema_time'=>$spreadSchemaTime,'preserve_keyword_order'=>count($keywords)>1,'broadcast_minutes'=>$broadcastMinutes,'event_url'=>$eventUrl,'schema_timestamp'=>$schemaTimestamp,'date'=>($date?->format('Y-m-d')??''),'images'=>$images],'event_url'=>$eventUrl,'rows'=>$rows,'description_mode'=>$useAI?'approved_ai':'template','description_revision'=>$revision,'descriptions'=>array_fill(0,$total,$description)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    db()->prepare('INSERT INTO eg_jobs (id,user_id,event,total,status) VALUES (?,?,?,?,?)')->execute([$id,$_SESSION['user_id'],$batchLabel,$total,'pending']);db()->commit();
   }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($createdDir){foreach(['template.html','template.html.tmp','state.json','state.json.tmp'] as $name)@unlink($dir.'/'.$name);@rmdir($dir);}throw $e;}
   jsonOut(['id'=>$id,'total'=>$total,'completed'=>0]);
  }
  if($path==='/api/step'){
   $id=$_POST['id']??'';$dir=jobDir($id);$q=db()->prepare('SELECT * FROM eg_jobs WHERE id=? AND user_id=?');$q->execute([$id,$_SESSION['user_id']]);$job=$q->fetch();if(!$job)jsonOut(['error'=>'Batch not found.'],404);
   $lock=fopen($dir.'/batch.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('This batch is already processing. Wait and resume.');
   try{
    $state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);$done=count($state['descriptions']);$total=count($state['rows']);
    if($done<$total){
     require_once __DIR__.'/app/OpenAI.php';@set_time_limit(75);
     $key=setting('api_key');if($key==='')throw new RuntimeException('Add your OpenAI key in Settings.');
     $batch=array_slice($state['rows'],$done,10);
     $new=OpenAI::descriptions(cryptKey($key,true),setting('model','gpt-4.1-mini'),$state['event'],$state['date'],$batch,$state['descriptions']);
     $state['descriptions']=array_merge($state['descriptions'],$new);$done=count($state['descriptions']);writeFileAtomic($dir.'/state.json',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    if($done===$total&&(!is_file($dir.'/html.zip')||!is_file($dir.'/urls.txt'))){if(($state['generator']??'')==='conn')ConnGenerator::package($dir,file_get_contents($dir.'/template.html'),$state);else EventGenerator::package($dir,file_get_contents($dir.'/template.html'),$state['rows'],$state['descriptions'],$state['base64'],$state['url_domains']??$state['url_domain']??'',$state['render_options']??[],(bool)($state['randomize_domains']??false));}
    $status=$done===$total?'complete':'pending';db()->prepare('UPDATE eg_jobs SET completed=?,status=? WHERE id=?')->execute([$done,$status,$id]);
   }finally{flock($lock,LOCK_UN);fclose($lock);}
   jsonOut(['id'=>$id,'completed'=>$done,'total'=>$total,'complete'=>$status==='complete','generation_mode'=>$state['generator']??'event']);
  }
  jsonOut(['error'=>'Unknown endpoint.'],404);
 }
 if($path==='/download'){
  $id=$_GET['id']??'';$dir=jobDir($id);$q=db()->prepare('SELECT * FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$id,$_SESSION['user_id'],'complete']);$job=$q->fetch();
  if(!$job){http_response_code(404);exit('Download not found.');}
  $type=$_GET['type']??'';if(!in_array($type,['zip','txt'],true)){http_response_code(404);exit;}
  $file=$dir.($type==='zip'?'/html.zip':'/urls.txt');if(!is_file($file)){http_response_code(404);exit('File unavailable.');}
  $downloadLock=fopen($dir.'/batch.lock','c');if(!$downloadLock||!flock($downloadLock,LOCK_SH|LOCK_NB)){http_response_code(409);exit('This batch is processing or being deleted. Retry shortly.');}
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  header('Content-Type: '.($type==='zip'?'application/zip':'text/plain; charset=utf-8'));header('Content-Disposition: attachment; filename="'.substr(EventGenerator::slug($job['event']),0,100).'-'.($type==='zip'?'html.zip':'urls.txt').'"');header('Content-Length: '.filesize($file));readfile($file);exit;
 }
 if($path==='/template/download'){
  $file=@fopen(templatePath(),'rb');if($file===false){http_response_code(404);exit('Saved HTML template not found. Upload one in Settings.');}
  $stat=fstat($file);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="saved-template.html"');header('Cache-Control: no-store');if($stat!==false)header('Content-Length: '.$stat['size']);
  try{fpassthru($file);}finally{fclose($file);}exit;
 }
 // Large bank downloads must not serialize this browser's API actions.
 if(in_array($path,['/id-bank/export','/title-bank/export','/movies/titles/export','/movies/ids/export'],true)&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
 if($path==='/id-bank/export'){(new IdBank(db()))->export();exit;}
 if($path==='/title-bank/export'){(new TitleBank(db()))->export();exit;}
 if($path==='/indexing'){require_once __DIR__.'/app/IndexingPage.php';indexingPage();}
 if($path==='/trash'){require_once __DIR__.'/app/BatchTrash.php';batchTrashPage();}
 if($path==='/settings'){
  $bank=new TitleBank(db());$bank->ensureSchema(ROOT.'/storage');
  $idBank=new IdBank(db());$idBank->ensureSchema();
  $notice='';
  if($_SERVER['REQUEST_METHOD']==='POST'){
   try{
    verifyCsrf();$model=trim($_POST['model']??'');if(!preg_match('/^[a-zA-Z0-9._:-]{1,100}$/',$model))throw new InvalidArgumentException('Enter a valid model ID.');
    $key=trim($_POST['api_key']??'');if($key!==''&&(strlen($key)<15||strlen($key)>512||preg_match('/\s/',$key)))throw new InvalidArgumentException('Enter a valid API key.');
    $newTemplateFile=null;
    $imageUrls=array_key_exists('image_urls',$_POST)?TemplateRenderer::imageUrls($_POST['image_urls']):null;
    $broadcastConfig=isset($_POST['broadcast_durations_present'])?BroadcastDurations::parse($_POST['broadcast_sport']??[],$_POST['broadcast_value']??[],$_POST['broadcast_unit']??[]):null;
    $template=null;$newTemplateFile=null;
    if(($_FILES['template']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
     $f=$_FILES['template'];if($f['error']!==UPLOAD_ERR_OK||$f['size']>3*1024*1024)throw new InvalidArgumentException('Upload an HTML or TXT template up to 3 MB.');
     if(!in_array(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)),['html','htm','txt'],true))throw new InvalidArgumentException('Template must be .html, .htm or .txt.');
     $template=file_get_contents($f['tmp_name']);if(!mb_check_encoding($template,'UTF-8')||!preg_match('~<title\b~i',$template))throw new InvalidArgumentException('Template must be UTF-8 HTML with a title element.');
    }
    if($template!==null){$newTemplateFile='template-'.bin2hex(random_bytes(12)).'.html';writeFileAtomic(ROOT.'/storage/'.$newTemplateFile,$template);}
    db()->beginTransaction();if($broadcastConfig!==null){saveSetting('broadcast_durations',json_encode($broadcastConfig,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));saveSetting('broadcast_durations_version','2');}saveSetting('model',$model);if($imageUrls!==null)saveSetting('image_urls',implode("\n",$imageUrls));if($key!=='')saveSetting('api_key',cryptKey($key));
    if($newTemplateFile!==null){saveSetting('template_file',$newTemplateFile);saveSetting('template_name',mb_substr(basename(str_replace('\\','/',$_FILES['template']['name'])),0,255));saveSetting('template_uploaded_at',gmdate('c'));}
    db()->commit();
    $notice=message('Settings saved.','success');
   }catch(InvalidArgumentException|RuntimeException $e){if(db()->inTransaction())db()->rollBack();if($newTemplateFile!==null)@unlink(ROOT.'/storage/'.$newTemplateFile);$notice=message($e instanceof PDOException?'Could not save database settings.':$e->getMessage());}
  }
  $templateName=setting('template_name','template.html (existing template)');$uploadedAt=setting('template_uploaded_at');
  $uploadedLabel=$uploadedAt?(new DateTimeImmutable($uploadedAt))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)':'Not recorded — saved before this update or included with installation';
  $templateStatus='<div class="notice success"><strong>Saved Template</strong><br>Filename: '.h($templateName).'<br>Uploaded: '.h($uploadedLabel).'</div>';
  $body='<h1>Settings</h1>'.$notice.'<div class="settings-layout"><div class="settings-main"><section class="panel narrow"><form method="post" enctype="multipart/form-data" class="stack" data-busy="Saving…">'.tokenField().'<h2>OpenAI Connection</h2>'.input('OpenAI API Key','api_key','password','',setting('api_key')!==''?'A key is saved. Leave blank to keep it.':'Your key is encrypted at rest and is never shown in the browser.',false).input('Model ID','model','text',setting('model','gpt-4.1-mini'),'Use a Responses API model with Structured Outputs support.').'<hr><h2>HTML Template</h2>'.$templateStatus.'<a class="button secondary" href="/template/download">Download Saved HTML</a><label>Replace Template<input type="file" name="template" accept=".html,.htm,.txt"><small>UTF-8 .html, .htm or .txt · maximum 3 MB</small></label>'.BroadcastDurations::editor(BroadcastDurations::read(setting('broadcast_durations'),(int)setting('broadcast_durations_version','1'))).'<hr><details id="image-urls-section"><summary><h2 style="display:inline;margin:0">Image URLs</h2></summary><div class="stack"><label>Image URL List<textarea name="image_urls" id="image-url-values" rows="8" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg">'.h(setting('image_urls')).'</textarea><small>One image URL per box. Supports 500+ URLs, up to 10,000.</small></label><div id="image-url-boxes" class="stack" hidden></div><div id="image-url-actions" class="description-actions" hidden><button type="button" class="secondary" id="add-image-url">Add Image URL</button><button type="button" class="secondary" id="paste-image-urls">Paste URL List</button><button type="button" class="secondary" id="apply-image-urls" hidden>Apply URL List</button></div></div></details><button class="primary">Save Settings</button></form></section>';
  $body.=serverUploadPanel();
  $body.='</div><div class="settings-template-column settings-right"><section class="panel template-library"><div class="template-bank-heading"><h2>Template Library</h2><p>Manage the patterns used for your generated files.</p></div><div class="template-bank-tabs" role="tablist" aria-label="Template library" hidden><button type="button" role="tab" id="titles-tab" aria-controls="titles-panel" aria-selected="true" tabindex="0">Title Templates</button><button type="button" role="tab" id="ids-tab" aria-controls="ids-panel" aria-selected="false" tabindex="-1">ID Templates</button></div>';
  $bankInfo=$bank->info();$bankCount=(int)($bankInfo['count']??0);
  $bankDate=isset($bankInfo['saved_at'])?(new DateTimeImmutable($bankInfo['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)':'Not saved yet';
  $body.='<section class="panel narrow title-bank-panel" id="titles-panel"><h2>Title Templates</h2><p>Use <code>{event_name}</code> for the name, <code>{event}</code> for the sport and <code>{date}</code> or <code>{event_date}</code> for the date.</p><div class="notice success" id="title-bank-summary"><strong>'.number_format($bankCount).' saved titles</strong><br>Last saved: '.h($bankDate).'</div><p><a id="title-bank-export" class="button secondary" href="/title-bank/export" '.($bankCount?'':'hidden').'>Download Saved Titles</a></p><form id="title-bank-form" class="stack" method="post" action="/api/title-bank" enctype="multipart/form-data">'.tokenField().'<input type="hidden" name="action" value="import"><label>Paste Title Variations<textarea name="title_templates" rows="10" placeholder="++[{event}@TV] {event_name} LIVE Streams&#10;[Watch {event}] {event_name} — {date}"></textarea><small>One template per line. Existing titles stay saved. Duplicates are skipped.</small></label><label>Or Upload Titles TXT<input type="file" name="title_file" accept=".txt,text/plain"><small>UTF-8 TXT, up to 128 MB. A selected file overrides pasted text.</small></label><label>Save Mode<select name="title_mode"><option value="append">Add to saved titles</option><option value="replace">Replace saved title bank</option></select></label><button class="primary" id="save-title-bank">Save Titles</button><div class="notice" id="title-bank-result" hidden></div></form></section>';
  $bankInfo=$idBank->info();$bankCount=(int)($bankInfo['count']??0);
  $bankDate=isset($bankInfo['saved_at'])?(new DateTimeImmutable($bankInfo['saved_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)':'Not saved yet';
  $body.='<section class="panel narrow id-bank-panel" id="ids-panel"><h2>ID Templates</h2><p>Use <code>{event_name}</code> for a lowercase, dashed name: <code>psg-vs-barcelona</code>. Letters, numbers and dashes only. Underscores become dashes. Omit .html; no suffix is added.</p><div class="notice success" id="id-bank-summary"><strong>'.number_format($bankCount).' saved IDs</strong><br>Last saved: '.h($bankDate).'</div><p><a id="id-bank-export" class="button secondary" href="/id-bank/export" '.($bankCount?'':'hidden').'>Download Saved IDs</a></p><form id="id-bank-form" class="stack" method="post" action="/api/id-bank" enctype="multipart/form-data">'.tokenField().'<input type="hidden" name="action" value="import"><label>Paste ID Variations<textarea name="id_templates" rows="10" placeholder="video-{event_name}-live-liv&#10;watch-{event_name}-ustv3&#10;{event_name}-online-ghvf"></textarea><small>One template per line. Existing IDs stay saved. Duplicates are skipped.</small></label><label>Or Upload IDs TXT<input type="file" name="id_file" accept=".txt,text/plain"><small>UTF-8 TXT, up to 128 MB. A selected file overrides pasted text.</small></label><label>Save Mode<select name="id_mode"><option value="append">Add to saved IDs</option><option value="replace">Replace saved ID bank</option></select></label><p class="hint">IDs never repeat for the same keyword. Replacing the bank keeps usage history.</p><button class="primary" id="save-id-bank">Save IDs</button><div class="notice" id="id-bank-result" hidden></div></form></section>';
  $body.='</section>';
  require_once __DIR__.'/app/GitHubSettings.php';$body.=githubConnectPanel();
  $body.='</div></div>';
  page('Settings',$body);exit;
 }
 if($path==='/movies'){require_once __DIR__.'/app/MoviesPage.php';page('Movies',moviesPage());exit;}
 if(in_array($path,['/movies/titles/export','/movies/ids/export'],true)){require_once __DIR__.'/app/MovieBanks.php';$bank=$path==='/movies/titles/export'?new MovieTitleBank(db()):new MovieIdBank(db());$bank->ensureSchema();$bank->export();exit;}
 $connPage=$path==='/conn';
 if($path!=='/'&&!$connPage){http_response_code(404);page('Not Found','<h1>Page not found.</h1><a href="/">Back to Generator</a>');exit;}
 $body='<div class="intro"><div><h1>One event. <span>A whole set of files.</span></h1></div></div>';
 if($connPage)$body='<div class="intro"><div><h1>CONN <span>HTML + XML pairs.</span></h1></div></div>';
 $savedIds=(new IdBank(db()))->info();
 $savedBank=(new TitleBank(db()))->info();if(empty($savedBank['id']))$body.=message('Save your title templates in Settings before generating. Use {event_name} in every title.','info');
 if(setting('api_key')==='')$body.=message('OpenAI is optional. Add an API key in Settings only if you want AI descriptions.','info');
 $body.='<div class="workspace"><section class="panel"><div class="section-label"><span class="step">01</span><h2>Build Your Batch</h2></div><form id="generate-form" class="stack">'.tokenField().EventKeywords::editor().'<div class="grid"><label>Unicode %<input name="unicode_percent" type="number" min="0" max="100" step="1" value="50" required></label><label>Fresh %<input name="fresh_percent" type="number" min="0" max="100" step="1" value="50" required></label></div><div class="grid"><label>Event Type<select name="event_type">'.BroadcastDurations::options(BroadcastDurations::read(setting('broadcast_durations'),(int)setting('broadcast_durations_version','1'))).'</select><small>Used for {event} in your title templates.</small></label>'.input('Manual Event Type (Optional)','event_type_custom','text','','Overrides the dropdown.',false).'</div>'.BroadcastDurations::generalControl(BroadcastDurations::read(setting('broadcast_durations'),(int)setting('broadcast_durations_version','1'))).'<label class="toggle-row"><span>Enable BroadcastEvent Schema</span><input type="hidden" name="broadcast_enabled" value="0"><input type="checkbox" name="broadcast_enabled" value="1" role="switch" checked><span class="toggle-track" aria-hidden="true"></span></label><label>BroadcastEvent %<input name="broadcast_percent" type="number" min="0" max="100" step="1" value="100" required><small>Random share of each keyword’s files. 0% means none; 100% means all.</small></label><label class="toggle-row"><span>Spread Schema Time<small>URLs stay ordered from earliest to latest.</small></span><input type="checkbox" name="spread_schema_time" value="1" role="switch"><span class="toggle-track" aria-hidden="true"></span></label><label>Spread Duration (Hours)<input type="number" name="spread_hours" min="0.01" max="168" step="0.01" value="1" required></label><label>Domain Names (Optional)<textarea name="url_domains" rows="4" placeholder="https://example.com/&#10;https://example.org/&#10;https://example.net/"></textarea><small>One domain or URL per line. Randomizer on: one URL per domain in each shuffled batch. Off: 5 consecutive URLs per domain. Leave blank for query-only URLs.</small></label>'.'<label class="toggle-row"><span>Enable Domain Randomizer</span><input type="hidden" name="randomize_domains" value="0"><input type="checkbox" name="randomize_domains" value="1" role="switch"><span class="toggle-track" aria-hidden="true"></span></label>'.($connPage?connTemplateControl((int)$_SESSION['user_id']):savedXmlControl((int)$_SESSION['user_id'])).input('Event URL','event_url','url','','Replaces {event_url} in your HTML template.').'<div class="grid">'.input('Event Date (Optional)','date','text','','Leave blank to research tournament dates with OpenAI.',false).'<label>Total Files & URLs<input type="number" name="count" min="1" max="1000" step="1" required placeholder="Enter output count"><small>Type any whole number from 1 to 1000.</small></label></div><div hidden><label>Numeric Date Order<select name="date_order"><option value="auto">Auto — detect unambiguous dates</option><option value="dmy">Day / Month / Year</option><option value="mdy">Month / Day / Year</option></select></label><label>ID Source<select name="id_source"><option value="saved" selected>Saved ID Templates</option><option value="manual">Manual ID List / Automatic IDs</option></select><small>Saved templates are chosen randomly, used exactly, and never repeated for the same event keyword.</small></label><label>ID Seeds (Optional)<textarea name="id_seeds" rows="6" placeholder="watch-livv-on-tv-uk2&#10;livve-onlin-tv-free3&#10;watch-free-tv324&#10;online-watch-liv-uktv2"></textarea><small>One ID seed per line, up to 1,000 lines. Leave blank for automatic IDs.</small></label></div><details hidden><summary>ID Prefix Option</summary><div class="stack">';
 $body.=input('ID Prefix (Optional)','id_prefix','text','','Default: video-{event-slug}-live-{random-suffix}.html. Each URL uses the same ID without .html.',false).'</div></details><section class="description-controls"><label class="toggle-row"><span>Generate Description Using OpenAI<small>One approved description is reused in every file.</small></span><input type="checkbox" id="use-openai" name="use_openai" value="1" role="switch"><span class="toggle-track" aria-hidden="true"></span></label><p id="template-description-note" class="hint">Off: keep all descriptions exactly as saved in your uploaded HTML.</p><div id="ai-description-panel" hidden><p class="hint">Search the web for event times, TV channels and streaming options, then review and approve. Leave Event Date blank to research the current tournament’s start and end dates. If no viewing details are found, use the SurpriseStream fallback. Maximum 100 words.</p><div id="description-error" class="notice error" hidden></div><div id="description-review" hidden><p id="description-text" class="description-text"></p><small id="description-word-count"></small><div id="description-sources" class="description-sources" hidden></div><div id="description-status" class="notice info"></div><section id="schema-time-review" class="notice info" hidden><p id="schema-time-proposal"></p><div id="schema-time-status" role="status" aria-live="polite"></div><div class="grid"><label>Event Date · Bangladesh<input type="date" id="schema-manual-date" name="schema_manual_date"></label><label>Start Time · Bangladesh (24-hour)<input type="time" id="schema-manual-time" name="schema_manual_time" step="60"></label></div><div class="description-actions"><button type="button" class="secondary" id="use-manual-time">Use Manual Time</button></div><div class="description-actions"><button type="button" class="secondary" id="approve-schema-time">Approve Schema Time</button><button type="button" class="secondary" id="use-generation-time">Use Generation Time</button></div></section></div><div class="description-actions"><button type="button" class="secondary" id="generate-description">Generate Description</button><button type="button" class="primary" id="approve-description" hidden>Approve Description</button></div></div></section><button class="primary large" id="generate">'.activitySpinner(true).'<span class="generate-label">Generate Files & URLs</span><span class="generate-arrow" aria-hidden="true">↗</span></button></form></section>';
 $body.=generatorOutputHtml();if($connPage){$body=str_replace('One domain or URL per line. Randomizer on: one URL per domain in each shuffled batch. Off: 5 consecutive URLs per domain. Leave blank for query-only URLs.','Owned editor-lab endpoint, one URL per line. HTML/XML hosting uses the saved server Public URL.',$body);$body=preg_replace('~<label class="toggle-row"><span>Enable Domain Randomizer</span>.*?</label>~s','',$body);$body=str_replace('Download HTML ZIP','Download HTML + XML ZIP',$body);}
 page($connPage?'CONN':'Generator',$body);
}catch(Throwable $e){
 if($path==='/api/github-connect'&&!str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION['github_connect_error']=($e instanceof InvalidArgumentException||($e instanceof RuntimeException&&!$e instanceof PDOException))?$e->getMessage():'The connection could not finish. Check server configuration and retry.';session_write_close();header('Location: /settings#github-connect-section',true,303);exit;}
 if(str_starts_with($path,'/api/'))jsonOut(['error'=>($e instanceof InvalidArgumentException||($e instanceof RuntimeException&&!$e instanceof PDOException))?$e->getMessage():'The request could not finish. Check storage permissions and database connectivity.'],400);
 http_response_code(500);page('Request Failed',message('The request could not finish. Check database configuration and storage permissions.').'<a href="/">Return to workspace</a>');
}
