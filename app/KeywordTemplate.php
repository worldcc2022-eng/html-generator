<?php
declare(strict_types=1);

/** Remove repeated promotional wording from template literals, before substitution. */
final class KeywordTemplate {
 private static function comparisonWord(string $word):string {
  // Compare common styled Latin alphabets without rewriting the user's text.
  $word=preg_replace_callback('/./us',static function(array $m):string {
   $point=mb_ord($m[0],'UTF-8');
   foreach([[0xFF21,65],[0xFF41,97],[0x1D400,65],[0x1D41A,97],[0x1D434,65],[0x1D44E,97],[0x1D468,65],[0x1D482,97],[0x1D4D0,65],[0x1D4EA,97],[0x1D56C,65],[0x1D586,97],[0x1D5A0,65],[0x1D5BA,97],[0x1D5D4,65],[0x1D5EE,97],[0x1D608,65],[0x1D622,97],[0x1D63C,65],[0x1D656,97],[0x1D670,65],[0x1D68A,97]] as [$start,$ascii]){
    if($point>=$start&&$point<$start+26)return chr($ascii+$point-$start);
   }
   return $m[0];
  },$word);
  return mb_strtolower($word,'UTF-8');
 }
 public static function withoutDuplicates(string $template,string $event,bool $id=false):string {
  $promotional=['free','stream','streaming','livestream','livestreaming','online','live','watch'];
  preg_match_all('/[\p{L}\p{N}]+/u',$event,$matches);$skip=[];
  foreach($matches[0] as $word){$key=self::comparisonWord($word);if(in_array($key,$promotional,true))$skip[$key]=true;}
  if(!$skip)return $template;
  $removed=false;
  $parts=preg_split('/(\{[a-z_]+\})/i',$template,-1,PREG_SPLIT_DELIM_CAPTURE);
  foreach($parts as &$part){
   if(preg_match('/^\{[a-z_]+\}$/i',$part))continue;
   $part=preg_replace_callback('/[\p{L}\p{N}]+/u',static function(array $m)use($skip,&$removed):string{
    if(isset($skip[self::comparisonWord($m[0])])){$removed=true;return '';}
    return $m[0];
   },$part);
  }
  unset($part);$result=implode('',$parts);
  if(!$removed)return $template;
  if($id)return trim(preg_replace('/-+/','-',$result),'-');
  $result=preg_replace('/\[\s*\]|\(\s*\)/u','',$result);
  return trim(preg_replace('/\s+/u',' ',$result));
 }
}
