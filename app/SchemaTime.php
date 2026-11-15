<?php
declare(strict_types=1);
final class SchemaTime {
 public static function candidate(mixed $start,array $sources):?string {
  if(!$sources||!is_string($start)||!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:\.[0-9]{1,6})?(Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D',$start,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;
  try{return (new DateTimeImmutable($start))->setTimezone(new DateTimeZone('-05:00'))->format(DATE_ATOM);}catch(Exception $e){return null;}
 }
 private static function start(array $review):?string {
  return self::candidate($review['manual_event_start_at']??null,['manual'])??self::candidate($review['event_start_at']??null,$review['sources']??[]);
 }
 public static function manual(array $review,string $context,string $revision,string $date,string $time):array {
  $review=self::decide($review,$context,$revision,false);
  if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Enter a valid Bangladesh event date and start time (24-hour).');
  $start=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time,new DateTimeZone('Asia/Dhaka'));
  if(!$start||(int)$start->format('Y')<1||$start->format('Y-m-d H:i')!==$date.' '.$time)throw new InvalidArgumentException('Enter a valid Bangladesh event date and start time (24-hour).');
  $review['manual_event_start_at']=$start->setTimezone(new DateTimeZone('-05:00'))->format(DATE_ATOM);
  $review['schema_time_approved']=true;return $review;
 }
 public static function proposed(array $review):?string {
  $start=self::start($review);
  return $start?(new DateTimeImmutable($start))->modify('-30 minutes')->format(DATE_ATOM):null;
 }
 public static function spreadSeconds(mixed $hours,int $total):int {
  if((!is_string($hours)&&!is_int($hours)&&!is_float($hours))||!is_numeric($hours)||!is_finite((float)$hours)||(float)$hours<0.01||(float)$hours>168)throw new InvalidArgumentException('Enter a spread duration from 0.01 to 168 hours.');
  $seconds=(int)round((float)$hours*3600);
  if($total<1||$total>1000||$seconds<$total-1)throw new InvalidArgumentException('Increase spread hours or reduce the file count: unique schema times need at least one second between files.');
  return $seconds;
 }
 public static function spreadTimestamp(string $base,int $index,int $total,int $seconds=3600):string {
  if($total<1||$total>1000||$index<0||$index>=$total||$seconds<1||$seconds>604800||$seconds<$total-1)throw new InvalidArgumentException('Schema time spread requires 1–1,000 files and a valid file position.');
  $base=self::candidate($base,['generation']);if($base===null)throw new InvalidArgumentException('The schema time spread needs a valid base timestamp.');
  $seconds=$total===1?0:(int)round($index*$seconds/($total-1));
  return (new DateTimeImmutable($base))->modify('+'.$seconds.' seconds')->format(DATE_ATOM);
 }
 public static function timestamp(array $review):?string {return !empty($review['schema_time_approved'])?self::proposed($review):null;}
 public static function decide(array $review,string $context,string $revision,bool $approve):array {
  if(($review['context']??'')!==$context||!hash_equals($review['revision']??'',$revision)||$revision==='')throw new InvalidArgumentException('The description or event changed. Load the latest description before approving its time.');
  if($approve&&!self::proposed($review))throw new InvalidArgumentException('No event start time is available. Enter a manual Bangladesh date and time or use file-generation time.');
  $review['schema_time_approved']=$approve;return $review;
 }
 public static function view(array $review):array {
  $start=self::start($review);$schema=self::proposed($review);
  $local=$start?(new DateTimeImmutable($start))->setTimezone(new DateTimeZone('Asia/Dhaka')):null;
  $format=static fn($value)=>$value?(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('j F Y, g:ia').' Bangladesh time':'';
  return ['manual'=>!empty($review['manual_event_start_at']),'manual_date'=>$local?->format('Y-m-d')??'','manual_time'=>$local?->format('H:i')??'','available'=>$schema!==null,'event_start_at'=>$start,'schema_timestamp'=>$schema,'start_label'=>$format($start),'schema_label'=>$format($schema),'approved'=>(bool)self::timestamp($review)];
 }
}
