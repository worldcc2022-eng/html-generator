<?php
// Compatibility with v1.7.1. No encoding conversion or automatic repair.
declare(strict_types=1);
final class TextEncoding {
 public static function repair(string $text):string{return $text;}
 public static function html(string $text):string{return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');}
}
