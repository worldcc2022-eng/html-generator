<?php
declare(strict_types=1);
function movieYear(mixed $year):string {
 if(!is_string($year)||!preg_match('/^[1-9][0-9]{3}$/D',trim($year)))throw new InvalidArgumentException('Enter the movie year as four digits.');
 return trim($year);
}

function movieName(mixed $name):string {
 if(!is_string($name)||!mb_check_encoding($name,'UTF-8'))throw new InvalidArgumentException('Enter a valid movie name.');
 $name=trim($name);if($name===''||mb_strlen($name)>180||preg_match('/[<>\x00-\x1f]/u',$name))throw new InvalidArgumentException('Enter a movie name from 1 to 180 characters.');return $name;
}
