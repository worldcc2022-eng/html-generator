<?php
declare(strict_types=1);
final class PlaceholderRenderer {
 private const PARTS='~(<script\b[^>]*>[\s\S]*?</script\s*>|<meta\b[^>]*>)~i';
 private static function primaryImages(string $part):bool {return preg_match('~^<meta\b|^<script\b[^>]*\btype\s*=\s*(["\x27])\s*application/ld\+json\s*\1~i',$part)===1;}
 public static function hasMarkers(string $source):bool{return preg_match('/\{(?:title|description|image|date|datetime|event_url)\}/',$source)===1;}
 public static function validate(string $source,?string $description,array $images):void {
  if(!self::hasMarkers($source))return;
  if(str_contains($source,'{description}')&&($description===null||trim($description)===''||trim($description)==='{description}'))throw new InvalidArgumentException('This template needs a description. Generate and approve the GPT description before generating files.');
  if(str_contains($source,'{image}')){
   if(count(array_unique($images,SORT_STRING))<4)throw new InvalidArgumentException('Save at least 4 different image URLs in Settings for this template.');
   $ordinary=preg_replace_callback(self::PARTS,fn($m)=>self::primaryImages($m[0])?'':$m[0],$source);
   if(substr_count($ordinary,'{image}')<4)throw new InvalidArgumentException('Add at least 4 {image} placeholders outside metadata and schemas to use four different images in each file.');
  }
 }
 private static function validateEventUrl(string $url):void {
  if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($url,PHP_URL_SCHEME)?:''),['https','http'],true)||parse_url($url,PHP_URL_USER)!==null||preg_match('/[\x00-\x20]/',$url))throw new InvalidArgumentException('Enter a complete http:// or https:// Event URL.');
 }
 public static function prepare(string $source,string $eventUrl,?string $description,array $images):string {
  if(str_contains($source,'{event_url}'))self::validateEventUrl($eventUrl);
  if(!self::hasMarkers($source))return EventGenerator::eventUrl($source,$eventUrl);
  self::validate($source,$description??TemplateRenderer::description($source),$images);
  // An unmarked URL or script is part of the uploaded template, not a slot.
  return $source;
 }
 private static function schemaDates(string $script,string $timestamp):string {
  if(!preg_match('~^<script\b[^>]*\btype\s*=\s*(["\x27])\s*application/ld\+json\s*\1~i',$script))return $script;
  // Track object boundaries without rebuilding JSON, including damaged JSON-LD.
  // Dates belong only to the object that directly owns the field and @type.
  preg_match_all('~"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]~s',$script,$matches,PREG_OFFSET_CAPTURE);
  $tokens=$matches[0];$stack=[];$changes=[];
  $collect=static function(array $object)use(&$changes){if(in_array($object['type']??'',['NewsArticle','VideoObject'],true))foreach($object['dates'] as $slot)$changes[]=$slot;};
  foreach($tokens as $i=>[$token,$offset]){
   if($token==='{'){$stack[]=['kind'=>'object','type'=>'','dates'=>[]];continue;}
   if($token==='['){$stack[]=['kind'=>'array'];continue;}
   $top=count($stack)-1;if($top<0)continue;
   if($token==='}'&&$stack[$top]['kind']==='object'){$collect(array_pop($stack));continue;}
   if($token===']'&&$stack[$top]['kind']==='array'){array_pop($stack);continue;}
   if($stack[$top]['kind']!=='object'||!str_starts_with($token,'"')||($tokens[$i+1][0]??'')!==':'||!str_starts_with($tokens[$i+2][0]??'','"'))continue;
   $key=json_decode($token,true);if(!is_string($key))continue;$key=trim($key);
   if($key==='@type'){$type=json_decode($tokens[$i+2][0],true);if(is_string($type))$stack[$top]['type']=trim($type);}
   elseif(in_array($key,['datePublished','dateModified','uploadDate'],true))$stack[$top]['dates'][]=[$tokens[$i+2][1],strlen($tokens[$i+2][0])];
  }
  foreach($stack as $object)if($object['kind']==='object')$collect($object);
  usort($changes,fn($a,$b)=>$b[0]<=>$a[0]);$encoded=json_encode($timestamp,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  foreach($changes as [$offset,$length])$script=substr_replace($script,$encoded,$offset,$length);
  return $script;
 }
 private static function visibleDate(string $part,string $date):string {
  return preg_replace_callback('~(<(span|p)\b[^>]*\bclass\s*=\s*(["\x27])([^"\x27]*)\3[^>]*>)([\s\S]*?)(</\2\s*>)~i',function($m)use($date){
   if(!in_array('sdc-article-date__date-time',preg_split('/\s+/',trim($m[4])),true))return $m[0];
   preg_match('/^(\s*)(.*?)(\s*)$/s',$m[5],$content);
   return $m[1].$content[1].htmlspecialchars($date,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').$content[3].$m[6];
  },$part);
 }
 public static function render(string $source,string $title,?string $description,string $date,string $timestamp,array $images,string $eventUrl=''):string {
  self::validate($source,$description,$images);
  if(str_contains($source,'{event_url}'))self::validateEventUrl($eventUrl);
  $pool=array_values(array_unique($images,SORT_STRING));shuffle($pool);$pool=array_slice($pool,0,4);$position=0;
  // Select once per file; repeat this set instead of exhausting the saved bank.
  $pick=function()use($pool,&$position):string{return $pool[$position++%count($pool)];};
  $values=['title'=>$title,'description'=>$description??'','date'=>$date,'datetime'=>$timestamp,'event_url'=>$eventUrl];
  $parts=preg_split(self::PARTS,$source,-1,PREG_SPLIT_DELIM_CAPTURE);
  foreach($parts as &$part){
   $script=preg_match('~^<script\b~i',$part)===1;
   $primaryImage=self::primaryImages($part);
   $part=$script?self::schemaDates($part,$timestamp):self::visibleDate($part,$date);
   $part=preg_replace_callback('/\{(title|description|image|date|datetime|event_url)\}/',function($m)use($values,$pick,$script,$primaryImage,$pool){
    $value=$m[1]==='image'?($primaryImage?$pool[0]:$pick()):$values[$m[1]];
    if(!$script)return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
    // Replace within the existing quoted JSON/JavaScript value. Escape script
    // delimiters without rebuilding its object or changing surrounding bytes.
    return str_replace(['`','$'],['\\u0060','\\u0024'],substr(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),1,-1));
   },$part);
  }unset($part);
  return implode('',$parts);
 }
}
