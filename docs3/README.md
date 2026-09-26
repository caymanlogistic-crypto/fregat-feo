# PDF Parser Project

Веб-приложение для автоматического парсинга PDF-документов (поручений на перевозку отходов).

## Структура проекта

```
pdf/
├── bootstrap.php              # Точка входа: автозагрузчик и загрузка конфигурации
├── index.php                  # Главный контроллер (веб-интерфейс)
├── .gitignore                 # Git ignore правила
├── config/
│   └── config.example.php     # Пример конфигурации (скопировать в config.local.php)
├── src/
│   ├── Services/
│   │   ├── DatabaseService.php       # Сервис БД (Singleton)
│   │   ├── DadataService.php         # Сервис API DaData
│   │   ├── PythonWorkerService.php   # Сервис запуска Python-скриптов
│   │   └── WasteProcessingService.php # Сервис обработки отходов
│   └── Parsers/
│       └── JsonFormatter.php         # Утилита форматирования JSON
├── include/
│   ├── worker.py              # Python-скрипт для парсинга PDF
│   └── dadata.php             # [УСТАРЕЛ] Использовать DadataService
└── public/                    # [Для будущего расширения] Публичные ассеты
```

## Установка

1. Скопируйте конфигурацию:
   ```bash
   cp config/config.example.php config/config.local.php
   ```

2. Отредактируйте `config/config.local.php`:
   - Настройки подключения к БД
   - API-ключ DaData (если отличается от стандартного)

3. Установите зависимости Python:
   ```bash
   python3 -m venv venv
   source venv/bin/activate
   pip install pdfplumber
   ```

4. Убедитесь, что таблица `fkko` существует в БД

## Использование

Откройте `index.php` в браузере и загрузите PDF-файл для парсинга.

## Архитектура

### Сервисы (Services)
- **DatabaseService** - Работа с БД, кэширование запросов ФККО
- **DadataService** - Получение юр. адреса по ИНН через API DaData
- **PythonWorkerService** - Запуск worker.py через `proc_open()`
- **WasteProcessingService** - Обработка и исправление названий отходов

### Контроллер
- **index.php** - Обработка POST-запросов, инициализация сервисов, отображение результатов

### Утилиты
- **JsonFormatter** - Красивое форматирование JSON для вывода

## Расширение

Для добавления новой функциональности:
1. Создайте новый сервис в `src/Services/`
2. Зарегистрируйте его в `index.php` через конфигурацию
3. Используйте в контроллере

## Лицензия

Приватный проект
