<?php
namespace App\Services;

/**
 * Сервис для обработки данных парсинга
 */
class WasteProcessingService
{
    private DatabaseService $dbService;
    
    public function __construct(DatabaseService $dbService)
    {
        $this->dbService = $dbService;
    }
    
    /**
     * Исправить названия отходов через БД
     * @param array $data Данные с позициями
     * @return array Обработанные данные
     */
    public function fixWasteNames(array $data): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            return $data;
        }
        
        try {
            foreach ($data['items'] as &$item) {
                $code = isset($item['код ФККО']) ? trim((string)$item['код ФККО']) : '';
                $currentName = isset($item['наименование отхода']) ? $item['наименование отхода'] : '';
                
                if (empty($code)) {
                    $item['наименование отхода'] = $currentName . ' [ERROR: код ФККО отсутствует]';
                    $item['fkko_found'] = false;
                    continue;
                }
                
                $correctName = $this->dbService->getWasteNameByCode($code);
                
                if ($correctName) {
                    $item['наименование отхода'] = $correctName;
                    $item['fkko_found'] = true;
                } else {
                    $item['наименование отхода'] = $currentName . ' [ERROR: код ' . $code . ' не найден в справочнике ФККО]';
                    $item['fkko_found'] = false;
                }
            }
        } catch (\PDOException $e) {
            error_log("DB Error: " . $e->getMessage());
            foreach ($data['items'] as &$item) {
                $currentName = isset($item['наименование отхода']) ? $item['наименование отхода'] : '';
                $item['наименование отхода'] = $currentName . ' [ERROR: БД недоступна]';
                $item['fkko_found'] = false;
            }
        }
        
        return $data;
    }
}
