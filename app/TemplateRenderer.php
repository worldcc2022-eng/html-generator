<?php
declare(strict_types=1);
require_once __DIR__.'/PlaceholderRenderer.php';
require_once __DIR__.'/BroadcastSchema.php';
final class TemplateRenderer {
 private static function jsonString(string $value):string {
  return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
 }
 private static function schemaValues(string $script,string $title,string $description,string $timestamp,?string $image):string {
  // Edit existing string values only. Do not parse, repair or rebuild JSON-LD.
  if(!preg_match('~"\s*@type\s*"\s*:\s*"\s*(NewsArticle|VideoObject)\s*"~i',$script,$type))return $script;
  $fields=['headline'=>$title,'alternativeHeadline'=>$title,'description'=>$description,'articleBody'=>$description,'datePublished'=>$timestamp,'dateModified'=>$timestamp,'uploadDate'=>$timestamp];
  if(strcasecmp($type[1],'VideoObject')===0)$fields['name']=$title;
  foreach($fields as $key=>$value){
   $limit=$key==='name'?1:-1;
   $script=preg_replace_callback('~("\s*'.preg_quote($key,'~').'\s*"\s*:\s*)"(?:\\\\.|[^"\\\\])*"~s',fn($m)=>$m[1].self::jsonString($value),$script,$limit);
  }
  if($image!==null){
   $script=preg_replace_callback('~("\s*(?:image|thumbnailUrl)\s*"\s*:\s*)("(?:\\\\.|[^"\\\\])*"|\[(?:"(?:\\\\.|[^"\\\\])*"|[^\]"\[])*\]|\{(?:"(?:\\\\.|[^"\\\\])*"|[^}"{])*\})~s',function($m)use($image){
    $value=$m[2];
    if($value[0]==='"')$value=self::jsonString($image);
    elseif($value[0]==='['){
     if(str_contains($value,'{'))return $m[0];
     $value=preg_replace_callback('~"(?:\\\\.|[^"\\\\])*"~s',fn()=>self::jsonString($image),$value);
    }
    else $value=preg_replace_callback('~("\s*(?:url|contentUrl)\s*"\s*:\s*)"(?:\\\\.|[^"\\\\])*"~s',fn($n)=>$n[1].self::jsonString($image),$value);
    return $m[1].$value;
   },$script);
  }
  return $script;
 }
 private static function imagePattern(string $source):string {
  $urls=['https://sports2live.online/cdn/images1.jpg'];
  preg_match_all('~<meta\b[^>]*>|<img\b[^>]*>~i',$source,$tags);
  foreach($tags[0] as $tag){
   if(str_starts_with(strtolower($tag),'<meta')){
    if(!preg_match('~\b(?:property|name)\s*=\s*(["\x27])\s*(?:og:image|twitter:image|x:image)\s*\1~i',$tag))continue;
    $attribute='content';
   }else{
    if(preg_match('~logo|favicon~i',$tag))continue;
    $attribute='src';
   }
   if(preg_match('~\b'. $attribute .'\s*=\s*(["\x27])(.*?)\1~is',$tag,$m)){
    $url=html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
    if(preg_match('~^https?://~i',$url))$urls[]=preg_replace('/[?#].*$/','',$url);
   }
  }
  $patterns=array_map(fn($url)=>preg_quote($url,'~'),array_unique($urls));usort($patterns,fn($a,$b)=>strlen($b)-strlen($a));
  return '~(?:'.implode('|',$patterns).')(?:\?[^\s"\x27<>,\]\\\\]*)?(?=[\s"\x27<>,\]\\\\]|$)~';
 }
 public static function imageUrls(string $text):array{
  if(strlen($text)>8*1024*1024)throw new InvalidArgumentException('Image URL list exceeds 8 MB.');
  $urls=[];
  foreach(preg_split('/\R/u',$text)?:[] as $line){
   $url=trim($line);if($url==='')continue;$p=parse_url($url);
   if(strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower($p['scheme']??''),['https','http'],true)||isset($p['user'])||isset($p['pass'])||preg_match('/[\x00-\x20<>"\x27]/',$url))throw new InvalidArgumentException('Enter one valid http(s) image URL per line, without login details.');
   $urls[$url]=true;if(count($urls)>10000)throw new InvalidArgumentException('Use up to 10,000 image URLs.');
  }
  return array_keys($urls);
 }
 public static function description(string $source):string{
  preg_match_all('~<meta\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>~i',$source,$tags);
  $fallback='';
  foreach($tags[0] as $tag){
   if(!preg_match('~\bcontent\s*=\s*(["\x27])(.*?)\1~is',$tag,$content))continue;
   $value=trim(html_entity_decode($content[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));
   if(preg_match('~\b(?:name|property)\s*=\s*(["\x27])\s*(.*?)\s*\1~is',$tag,$a)){
    if(strtolower(trim($a[2]))==='description')return $value;
    if(in_array(strtolower(trim($a[2])),['og:description','twitter:description','x:description'],true))$fallback=$fallback?:$value;
   }elseif(!str_contains(strtolower($tag),'og:description')&&$value!=='')return $value;
  }
  return $fallback;
 }
 public static function render(string $source,string $title,?string $description,array $options,array $titles):string{
  $description=$description??self::description($source);
  $date=$options['date']??'';if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new RuntimeException('Missing normalized event date. Generate a new batch.');
  $images=$options['images']??[];
  $timestamp=(new DateTimeImmutable($options['schema_timestamp']??$options['generated_at']??'now'))->setTimezone(new DateTimeZone('-05:00'))->format(DATE_ATOM);
  if(($options['broadcast_enabled']??true)===false)$source=BroadcastSchema::disable($source);
  elseif(isset($options['broadcast_minutes']))$source=BroadcastSchema::apply($source,$timestamp,(int)$options['broadcast_minutes']);
  if(PlaceholderRenderer::hasMarkers($source))return PlaceholderRenderer::render($source,$title,$description,$date,$timestamp,$images,$options['event_url']??'');
  $image=$images?$images[random_int(0,count($images)-1)]:null;
  $escape=fn($v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');
  $oldImage=self::imagePattern($source);
  // Isolate scripts, comments and styles before editing visible article elements.
  $parts=preg_split('~(<!--[\s\S]*?-->|<(?:script|style)\b[^>]*>[\s\S]*?</(?:script|style)\s*>)~i',$source,-1,PREG_SPLIT_DELIM_CAPTURE);
  foreach($parts as &$part){
   if(preg_match('~^(?:<!--|<style\b)~i',$part))continue;
   if(preg_match('~^<script\b~i',$part)){
    if(preg_match('~^<script\b[^>]*\btype\s*=\s*(["\x27])\s*application/ld\+json\s*\1~i',$part)){
     $target=preg_match('~"\s*@type\s*"\s*:\s*"\s*(?:NewsArticle|VideoObject)\s*"~i',$part);
     $pattern=$target?$oldImage:'~https://sports2live\.online/cdn/images1\.jpg(?:\?[^\s"\x27<>,]*)?~';
     if($image)$part=preg_replace_callback($pattern,fn()=>substr(self::jsonString($image),1,-1),$part);
     $part=self::schemaValues($part,$title,$description,$timestamp,$image);
    }
    continue;
   }
   // Replace template image references before inserting approved text. An image
   // URL mentioned in the GPT description must remain part of that description.
   if($image)$part=preg_replace_callback($oldImage,fn()=>$escape($image),$part);
   $part=preg_replace_callback('~<h1\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>~i',function($m)use($title,$escape){
    if(!preg_match('~\bclass\s*=\s*(["\x27])(.*?)\1~is',$m[0],$classes))return $m[0];
    if(!in_array('sdc-article-header__title',preg_split('/\s+/',trim($classes[2])),true))return $m[0];
    return preg_replace_callback('~(\bdata-short-title\s*=\s*)(["\x27])(.*?)\2~is',fn($attribute)=>$attribute[1].$attribute[2].$escape($title).$attribute[2],$m[0]);
   },$part);
   $part=preg_replace_callback('~(<(span|p)\b[^>]*\bclass\s*=\s*(["\x27])([^"\x27]*)\3[^>]*>)([\s\S]*?)(</\2\s*>)~i',function($m)use($escape,$title,$date){
    $classes=preg_split('/\s+/',trim($m[4]));$value=null;
    if(array_intersect($classes,['sdc-article-header__long-title','ui-media-caption__caption-text','sdc-site-tile__headline-text']))$value=$title;
    elseif(in_array('sdc-article-date__date-time',$classes,true))$value=$date;
    return $value===null?$m[0]:$m[1].$escape($value).$m[6];
   },$part);
  }unset($part);
  $source=implode('',$parts);
  $source=EventGenerator::metadata($source,$title,$description!==''?$description:null);
  // Repair the malformed OG marker into a usable attribute after content replacement.
  $source=preg_replace('~(<meta\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*?)\s+og:description["\x27]\s*(/?>)~i','$1 property="og:description"$2',$source);
  return $source;
 }
}
