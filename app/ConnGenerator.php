<?php
declare(strict_types=1);
require_once __DIR__.'/Generator.php';
require_once __DIR__.'/TemplateRenderer.php';
final class ConnGenerator {
 public const LAB_HOST='html.surprisestream.com';
 private const LAB_PATH='/connector-router/editor-lab/fckeditor/editor/filemanager/browser/default/browser.html';
 /** This test integration supports the app's own lab and its local hosting router. */
 public static function labEndpoints(string $text,string $hosting):array {
  $hosting=self::baseUrl($hosting);$endpoints=EventGenerator::urlDomains($text);if(!$endpoints)return [$hosting];
  foreach($endpoints as $endpoint){
   if(rtrim($endpoint,'/').'/'===$hosting)continue;
   $parts=parse_url($endpoint);$authority=strtolower($parts['host']??'').(isset($parts['port'])?':'.$parts['port']:'');
   $normalize=static fn($value)=>preg_replace('/:(?:80|443)$/D','',strtolower($value));
   if($normalize($authority)!==self::LAB_HOST||strtolower($parts['scheme']??'')!=='https'||($parts['path']??'')!==self::LAB_PATH)throw new InvalidArgumentException('This test integration accepts the EventForge app’s own editor-lab endpoint or the saved hosting URL.');
  }
  return $endpoints;
 }
 public static function assignEndpoints(array $endpoints,int $count,bool $randomize):array {
  if(!$endpoints||$count<1||$count>1000)throw new InvalidArgumentException('Invalid CONN endpoint allocation.');$assigned=[];$cycle=$endpoints;
  for($i=0;$i<$count;$i++){if($randomize&&$i%count($endpoints)===0){$cycle=$endpoints;shuffle($cycle);}$assigned[]=$cycle[($randomize?$i:intdiv($i,5))%count($endpoints)];}return $assigned;
 }
 public static function baseUrl(string $value):string {
  $value=trim($value);$url=EventGenerator::urlDomain($value);$p=parse_url($url);
  if($url===''||str_contains($p['path']??'','%')||str_contains($p['path']??'','\\')||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$p['path']??'')||preg_match('~[^a-zA-Z0-9/_.-]~',$p['path']??''))throw new InvalidArgumentException('Enter one hosting URL with a simple directory path, such as https://example.com/.');
  $scheme=strtolower($p['scheme']);$host=strtolower($p['host']);$port=$p['port']??null;
  return $scheme.'://'.$host.($port!==null&&!(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80))?':'.$port:'').rtrim($p['path']??'','/').'/';
 }
 public static function template(string $value):string {
  if(strlen($value)>3*1024*1024||!mb_check_encoding($value,'UTF-8')||!str_contains($value,'{url}')||str_contains($value,"\0"))throw new InvalidArgumentException('Save a UTF-8 XML template up to 3 MB containing {url}.');
  return $value;
 }
 public static function ids(int $count,array $excluded=[]):array {
  if($count<1||$count>1000)throw new InvalidArgumentException('Enter a count from 1 to 1000.');
  $used=array_fill_keys($excluded,true);$out=[];
  while(count($out)<$count){$id=(string)random_int(100000000000000,999999999999999);if(!isset($used[$id])){$out[]=$id;$used[$id]=true;}}
  return $out;
 }
 public static function links(string $base,string $id,string $uuid,?string $outputEndpoint=null):array {
  $base=self::baseUrl($base);if(!preg_match('/^[1-9][0-9]{14}$/D',$id)||!preg_match('/^[1-9][0-9]{12}$/D',$uuid))throw new InvalidArgumentException('Invalid CONN file mapping.');
  $host=substr($base,strpos($base,'://')+3);
  return ['id'=>$id,'html_url'=>$base.'video/posts/'.$id.'?Command=GetFoldersAndFiles','xml_url'=>$base.'video/&GetFoldersAndFiles='.$id.'?Command=GetFoldersAndFiles&Type=File&CurrentFolder=/&uuid='.$uuid,'output_url'=>($outputEndpoint??$base).'?Type=File&CONNECTOR='.rawurlencode('/\\/'.$host.'video/&GetFoldersAndFiles').'='.$id];
 }
 public static function xml(string $template,string $htmlUrl):string {return str_replace('{url}',$htmlUrl,self::template($template));}
 public static function routing():string {
  return "# BEGIN EventForge CONN v1\nRewriteEngine On\nRewriteRule ^video/posts/([0-9]{15})/?$ eventforge-conn.php [END]\nRewriteRule ^video/\x26GetFoldersAndFiles=([0-9]{15})/?$ eventforge-conn.php [END]\nRewriteCond %{QUERY_STRING} (^|&)CONNECTOR= [NC]\nRewriteRule ^$ eventforge-conn.php [END]\n# END EventForge CONN v1\n";
 }
 public static function router():string {return (string)file_get_contents(__DIR__.'/ConnRouter.php');}
 public static function package(string $dir,string $html,array $state):void {
  if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension to generate CONN batches.');
  $conn=$state['conn'];$base=self::baseUrl($conn['base_url']);$xml=self::template($conn['xml_template']);$rows=$state['rows'];$descriptions=$state['descriptions'];
  if(!$rows||count($rows)!==count($descriptions))throw new RuntimeException('CONN batch rows are incomplete.');
  $options=$state['render_options'];$options['generated_at']=$conn['generated_at'];$zip=new ZipArchive;$parts=[];$mapping=[];$seen=[];
  if($zip->open($dir.'/html.zip.tmp',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create CONN ZIP.');
  try{
   foreach($rows as $i=>$row){$id=$row['id'];if(isset($seen[$id]))throw new RuntimeException('Duplicate CONN ID.');$seen[$id]=true;$links=self::links($base,$id,$conn['uuids'][$i],$conn['output_assignments'][$i]??null);$mapping[]=$links;
    $fileOptions=$options;if(array_key_exists('broadcast_enabled',$row))$fileOptions['broadcast_enabled']=($options['broadcast_enabled']??true)&&$row['broadcast_enabled'];if(!empty($options['spread_schema_time']))$fileOptions['schema_timestamp']=SchemaTime::spreadTimestamp($options['schema_timestamp']??$options['generated_at'],$i,count($rows),(int)($options['spread_seconds']??3600));
    $content=TemplateRenderer::render($html,$row['title'],$descriptions[$i],$fileOptions,array_column($rows,'title'));
    foreach(['html'=>$content,'xml'=>self::xml($xml,$links['html_url'])] as $extension=>$bytes){$part=$dir.'/conn-part-'.$i.'.'.$extension.'.tmp';$parts[]=$part;if(file_put_contents($part,$bytes)!==strlen($bytes)||!$zip->addFile($part,'.eventforge-conn/'.$id.'.'.$extension))throw new RuntimeException('Could not write CONN pair. Check disk space.');}
   }
   foreach(['.eventforge-conn/.htaccess'=>"Require all denied\n",'eventforge-conn.php'=>self::router(),'CONN-ROUTING.txt'=>self::routing(),'conn-mapping.json'=>json_encode($mapping,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)] as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new RuntimeException('Could not add CONN routing files.');
  }finally{$closed=$zip->close();foreach($parts as $part)@unlink($part);}
  if(!$closed)throw new RuntimeException('Could not finalize CONN ZIP.');
  $urls=implode("\n",array_column($mapping,'output_url'))."\n";
  if(file_put_contents($dir.'/urls.txt.tmp',$urls)!==strlen($urls)||!rename($dir.'/html.zip.tmp',$dir.'/html.zip')||!rename($dir.'/urls.txt.tmp',$dir.'/urls.txt'))throw new RuntimeException('Could not finish CONN downloads.');
 }
}
function connTemplateControl(int $user):string {
 return '<section class="notice info"><h3>CONN XML Template</h3><p>Only {url} is replaced with its matching HTML URL. All other XML content stays as saved.</p><label>Saved XML Template<textarea id="conn-xml-template" rows="8" placeholder="Paste your XML containing {url}">'.h(setting('conn_xml_template_'.$user)).'</textarea></label><button type="button" class="secondary" id="conn-template-save">Save XML Template</button><div id="conn-template-result" class="notice" hidden role="status"></div></section><input type="hidden" name="generator" value="conn">';
}
