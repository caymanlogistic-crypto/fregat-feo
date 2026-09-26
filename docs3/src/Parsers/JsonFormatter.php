<?php
namespace App\Parsers;

/**
 * Утилита для кодирования данных в JSON с форматированием
 */
class JsonFormatter
{
    /**
     * Кодировать данные в JSON с красивым форматированием
     * @param mixed $data Данные для кодирования
     * @param int $indent Уровень отступа
     * @return string JSON строка
     */
    public static function encode($data, int $indent = 0): string
    {
        $sp = str_repeat('  ', $indent);
        $sp2 = str_repeat('  ', $indent + 1);
        
        if (is_null($data)) return 'null';
        if (is_bool($data)) return $data ? 'true' : 'false';
        if (is_int($data)) return (string)$data;
        
        if (is_float($data)) {
            $s = sprintf('%.10f', $data);
            $s = rtrim($s, '0');
            $s = rtrim($s, '.');
            return $s;
        }
        
        if (is_string($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
        if (is_array($data)) {
            $isList = array_keys($data) === range(0, count($data) - 1);
            if (empty($data)) return $isList ? '[]' : '{}';
            
            $items = [];
            foreach ($data as $k => $v) {
                $val = self::encode($v, $indent + 1);
                if ($isList) {
                    $items[] = $sp2 . $val;
                } else {
                    $key = json_encode((string)$k, JSON_UNESCAPED_UNICODE);
                    $items[] = $sp2 . $key . ': ' . $val;
                }
            }
            
            if ($isList) {
                return "[\n" . implode(",\n", $items) . "\n" . $sp . "]";
            } else {
                return "{\n" . implode(",\n", $items) . "\n" . $sp . "}";
            }
        }
        
        return 'null';
    }
}
