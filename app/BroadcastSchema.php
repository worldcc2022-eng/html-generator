<?php
declare(strict_types=1);
final class BroadcastSchema {
 public static function percentage(mixed $value):int {
  if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]?|100)$/D',(string)$value))throw new InvalidArgumentException('Enter a whole BroadcastEvent percentage from 0 to 100.');
  return (int)$value;
 }
 /** Freeze the random selection on rows without changing chronological URL order. */
 public static function distribute(array $rows,int $percent):array {
  self::percentage($percent);$groups=[];
  foreach($rows as $index=>$row){$groups[$row['keyword']??''][]=$index;$rows[$index]['broadcast_enabled']=false;}
  foreach($groups as $indices){$count=(int)round(count($indices)*$percent/100);shuffle($indices);foreach(array_slice($indices,0,$count) as $index)$rows[$index]['broadcast_enabled']=true;}
  return $rows;
 }
 private static function encoded(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);}
 public static function apply(string $html,string $start,int $minutes):string {
  if($minutes<1||$minutes>10080)throw new InvalidArgumentException('Broadcast duration must be 1–10,080 minutes.');
  $end=(new DateTimeImmutable($start))->modify('+'.$minutes.' minutes')->format(DATE_ATOM);
  $event=['@type'=>'BroadcastEvent','isLiveBroadcast'=>true,'startDate'=>$start,'endDate'=>$end];
  return self::edit($html,$event);
 }
 public static function disable(string $html):string{return self::edit($html,null);}
 private static function edit(string $html,?array $event):string {
  return preg_replace_callback('~<script\b[^>]*>[\s\S]*?</script\s*>~i',function($match)use($event){
   $script=$match[0];if(!preg_match('~^<script\b[^>]*\btype\s*=\s*(["\x27])\s*application/ld\+json\s*\1~i',$script))return $script;
   // Locate object fields and offsets without rebuilding or repairing JSON-LD.
   preg_match_all('~"(?:\\\\.|[^"\\\\])*"|true|false|null|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[{}\[\]:,]~s',$script,$m,PREG_OFFSET_CAPTURE);$tokens=$m[0];$stack=[];$nodes=[];$closing=[];
   foreach($tokens as $i=>[$token,$offset]){
    if($token==='{'||$token==='['){$n=count($nodes);$nodes[]=['parent'=>$stack?end($stack):null,'kind'=>$token,'open'=>$i,'start'=>$offset,'close'=>null,'fields'=>[]];$stack[]=$n;}
    elseif($token==='}'||$token===']'){
     if(!$stack)continue;$n=end($stack);if(($token==='}'&&$nodes[$n]['kind']!=='{')||($token===']'&&$nodes[$n]['kind']!=='['))continue;
     array_pop($stack);$nodes[$n]['close']=$i;$nodes[$n]['end']=$offset;$closing[$nodes[$n]['open']]=$i;
    }
   }
   foreach($nodes as &$node){
    if($node['kind']!=='{'||$node['close']===null)continue;
    for($i=$node['open']+1;$i<$node['close'];$i++){
     if(isset($closing[$i])){$i=$closing[$i];continue;}
     if(!str_starts_with($tokens[$i][0],'"')||($tokens[$i+1][0]??'')!==':')continue;
     $key=json_decode($tokens[$i][0],true);if(!is_string($key))continue;$v=$i+2;if(!isset($tokens[$v]))continue;
     $last=$closing[$v]??$v;$offset=$tokens[$v][1];$length=$tokens[$last][1]+strlen($tokens[$last][0])-$offset;
     $node['fields'][trim($key)]=['start'=>$offset,'length'=>$length,'raw'=>substr($script,$offset,$length),'token'=>$tokens[$v][0],'last'=>$last];$i=$last;
    }
    $type=json_decode($node['fields']['@type']['raw']??'null',true);$node['types']=is_array($type)?array_map(fn($v)=>is_string($v)?trim($v):'', $type):[is_string($type)?trim($type):''];
   }unset($node);
   $changes=[];
   if($event===null){
    $removed=[];
    foreach($nodes as $index=>$node){
     if(!in_array('BroadcastEvent',$node['types']??[],true))continue;
     $other=array_values(array_filter($node['types'],fn($type)=>$type!=='BroadcastEvent'));
     if($other){$field=$node['fields']['@type'];$changes[]=[$field['start'],$field['length'],self::encoded(count($other)===1?$other[0]:$other)];continue;}
     if($node['parent']===null)return '';
     $removed[$index]=true;
    }
    foreach($nodes as $index=>$node){
     if($node['kind']!=='['||$node['close']===null)continue;
     $members=[];
     for($i=$node['open']+1;$i<$node['close'];$i++){
      if($tokens[$i][0]===',')continue;
      $last=$closing[$i]??$i;$remove=false;
      foreach($nodes as $child=>$candidate)if($candidate['open']===$i&&isset($removed[$child])){$remove=true;break;}
      $members[]=['start'=>$tokens[$i][1],'end'=>$tokens[$last][1]+strlen($tokens[$last][0]),'remove'=>$remove];$i=$last;
     }
     for($i=0;$i<count($members);$i++){
      if(!$members[$i]['remove'])continue;$first=$i;
      while(isset($members[$i+1])&&$members[$i+1]['remove'])$i++;
      if(isset($members[$i+1])){$start=$members[$first]['start'];$end=$members[$i+1]['start'];}
      elseif($first>0){$start=$members[$first-1]['end'];$end=$members[$i]['end'];}
      else{$start=$node['start']+1;$end=$node['end'];}
      $changes[]=[$start,$end-$start,''];
     }
    }
    foreach($removed as $index=>$_){$node=$nodes[$index];if($nodes[$node['parent']]['kind']==='{')$changes[]=[$node['start'],$node['end']+1-$node['start'],'null'];}
    // Drop edits covered by an outer removed object/array member.
    $changes=array_values(array_filter($changes,static function($change)use($changes){foreach($changes as $outer)if($outer[0]<=$change[0]&&$outer[0]+$outer[1]>=$change[0]+$change[1]&&$outer[1]>$change[1])return false;return true;}));
    usort($changes,fn($a,$b)=>$b[0]<=>$a[0]);foreach($changes as [$offset,$length,$value])$script=substr_replace($script,$value,$offset,$length);return $script;
   }

   $append=static function(array $node,array $fields)use($script,&$changes){
    if(!$fields)return;$before=rtrim(substr($script,0,$node['end']));$last=substr($before,-1);$prefix=in_array($last,['{','[',','],true)?'':',';
    $pieces=[];foreach($fields as $key=>$value)$pieces[]=BroadcastSchema::encoded($key).':'.BroadcastSchema::encoded($value);
    $changes[]=[$node['end'],0,$prefix.implode(',',$pieces)];
   };
   foreach($nodes as $video){
    if(!in_array('VideoObject',$video['types']??[],true))continue;
    $publication=$video['fields']['publication']??null;
    if($publication===null){$append($video,['publication'=>[$event]]);continue;}
    $publicationNode=null;foreach($nodes as $index=>$node)if($node['start']===$publication['start']){$publicationNode=$index;break;}
    $broadcasts=[];foreach($nodes as $index=>$node)if(in_array('BroadcastEvent',$node['types']??[],true)&&(($publication['token']==='{'&&$index===$publicationNode)||($publication['token']==='['&&$node['parent']===$publicationNode)))$broadcasts[]=$node;
    if($broadcasts){foreach($broadcasts as $node){$missing=[];foreach(['isLiveBroadcast','startDate','endDate'] as $key){$field=$node['fields'][$key]??null;if($field)$changes[]=[$field['start'],$field['length'],self::encoded($event[$key])];else $missing[$key]=$event[$key];}$append($node,$missing);}continue;}
    if($publication['token']==='['){$offset=$tokens[$publication['last']][1];$before=rtrim(substr($script,0,$offset));$prefix=in_array(substr($before,-1),['[',','],true)?'':',';$changes[]=[$offset,0,$prefix.self::encoded($event)];}
    elseif($publication['token']==='{'){$changes[]=[$publication['start'],0,'['];$changes[]=[$publication['start']+$publication['length'],0,','.self::encoded($event).']'];}
    else throw new InvalidArgumentException('VideoObject publication must be an object or array for BroadcastEvent support.');
   }
   usort($changes,fn($a,$b)=>$b[0]<=>$a[0]);foreach($changes as [$offset,$length,$value])$script=substr_replace($script,$value,$offset,$length);return $script;
  },$html);
 }
}
