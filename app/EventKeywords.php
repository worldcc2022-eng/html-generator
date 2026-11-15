<?php
declare(strict_types=1);
final class EventKeywords {
 public static function parse(mixed $first,mixed $additional=[]):array {
  if(!is_string($first)||!is_array($additional)||count($additional)>999)throw new InvalidArgumentException('Enter up to 1,000 event keywords using the Add button.');
  $keywords=[];$seen=[];
  foreach(array_merge([$first],array_values($additional)) as $i=>$name){
   if(!is_string($name))throw new InvalidArgumentException('Enter a valid event keyword.');
   $name=trim($name);if($i>0&&$name==='')continue;
   if(!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>180||mb_strlen($name)<3||preg_match('/[<>\x00-\x1f]/u',$name))throw new InvalidArgumentException('Enter a valid event keyword, 3–180 characters.');
   $key=mb_strtolower(preg_replace('/\s+/u',' ',$name),'UTF-8');
   if(isset($seen[$key]))continue;
   $seen[$key]=true;$keywords[]=$name;
  }
  return $keywords;
 }
 public static function allocate(array $keywords,int $total):array {
  if(!$keywords||$total<count($keywords)||$total>1000)throw new InvalidArgumentException('Total files must be at least the number of event keywords, up to 1,000.');
  $each=intdiv($total,count($keywords));$extra=$total%count($keywords);$out=[];
  foreach($keywords as $i=>$name)$out[]=['keyword'=>$name,'count'=>$each+($i<$extra?1:0)];
  return $out;
 }
 /** The caller reserves every keyword inside one database transaction. */
 public static function rows(array $allocation,bool $randomize,callable $pickIds,callable $pickTitles):array {
  $rows=[];$usedIds=[];$usedTitles=[];
  foreach($allocation as $part){
   $keyword=$part['keyword'];$count=$part['count'];
   try{$ids=$pickIds($keyword,$count,array_keys($usedIds));$titles=$pickTitles($keyword,$count);}
   catch(InvalidArgumentException $e){throw new InvalidArgumentException('Keyword "'.$keyword.'": '.$e->getMessage(),0,$e);}
   if(count($ids)!==$count||count($titles)!==$count)throw new RuntimeException('Incomplete keyword allocation. No files were generated.');
   foreach($titles as $i=>$title){
    $id=$ids[$i];$titleKey=mb_strtolower(trim(preg_replace('/\s+/u',' ',$title)),'UTF-8');
    if(isset($usedIds[$id])||isset($usedTitles[$titleKey]))throw new InvalidArgumentException('The selected keywords produce duplicate titles or IDs. Use distinct keywords or add more templates. No titles or IDs were consumed.');
    $usedIds[$id]=true;$usedTitles[$titleKey]=true;$rows[]=['id'=>$id,'title'=>$title,'keyword'=>$keyword];
   }
  }
  if($randomize)shuffle($rows);
  return $rows;
 }
 public static function editor():string {
  return '<section id="event-keywords" class="stack"><div class="xml-url-control"><label for="event-keyword-input">Event Keywords</label><div class="image-url-row"><input id="event-keyword-input" name="event" type="text" minlength="3" maxlength="180" required><button type="button" class="secondary" id="add-event-keyword">Add</button></div></div><div id="event-keyword-fields" class="stack"></div><template id="event-keyword-template"><div class="image-url-row" data-keyword-row><input name="event_keywords[]" type="text" aria-label="Additional event keyword" minlength="3" maxlength="180" required><button type="button" class="secondary" data-remove-keyword>Remove</button></div></template><label class="toggle-row"><span>Enable Keyword Randomizer</span><input type="checkbox" name="randomize_keywords" value="1" role="switch"><span class="toggle-track" aria-hidden="true"></span></label></section>';
 }
}
