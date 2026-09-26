# Локальная разработка LEGACY FREGAT FEO

Эта рабочая область предназначена только для старой системы FREGAT FEO. Она не использует код, процессы, порты, базы или конфигурацию ERP PLANEX.

## Безопасное начало задачи

1. Создайте отдельную ветку и linked worktree от актуального `main`:

   ```powershell
   $TaskId = "short-task-name"
   git worktree add ".worktrees/$TaskId" -b "codex/$TaskId" main
   ```

2. Перед изменениями проверьте рабочее дерево и владельца runtime:

   ```powershell
   git status --short --branch
   docker compose -p fregat-feo ps
   Get-NetTCPConnection -LocalPort 8080 -State Listen
   ```

3. Не запускайте второй runtime с тем же Compose project, портом или volume. Для реально одновременных runtime-задач сначала выделяются отдельные project name, порт, база и volume.

## Файлы разработки

- `map2.php` — зафиксированный baseline. Без отдельной команды OWNER его не изменять.
- `maptest.php` и `map_files/*` — рабочие точки для доработки и локальной проверки.
- PHP, JavaScript, CSS и Markdown сохраняются в UTF-8 без BOM.
- Изменения JavaScript и CSS подключаются с версией через `filemtime`, чтобы браузер не использовал старый кэш.
- `.env`, реальные `config.php`, SQL-дампы, логи и runtime-файлы остаются локальными и не добавляются в Git.

## Локальный runtime

Запуск выполняется только из worktree, которому передано владение runtime:

```powershell
docker compose -p fregat-feo up --build -d
docker compose -p fregat-feo ps
```

Рабочая страница разработки:

`http://127.0.0.1:8080/fregat/feo/maptest.php`

Контрольный baseline:

`http://127.0.0.1:8080/fregat/feo/map2.php`

MySQL не публикуется на хост. Данные хранятся только в project-scoped volume `fregat-feo_feo_mysql_data`.

## Проверка перед коммитом

Из корня task-worktree выполните:

```powershell
powershell -ExecutionPolicy Bypass -File tools/verify-local.ps1
```

Скрипт ничего не запускает и не останавливает. Он проверяет Git, владельца уже работающих контейнеров, HTTP `maptest.php`, синтаксис PHP/JavaScript и локальную базу.

После проверки добавляйте только файлы текущей задачи, без `git add .`.

## Необязательная зависимость `../erpv2`

Основная карта не требует каталога `../erpv2` для запуска. Историческая зависимость встречается только в отдельных местах:

- `planex-flights-mapping-diagnostic.php` ищет соседний каталог `../erpv2` и является диагностическим инструментом;
- `index22.php` содержит внешнюю пользовательскую ссылку на `https://plan-ex.ru/erpv2/company/feo/requests`.

Эти места не дают разрешения читать или изменять локальный проект ERP PLANEX. Если диагностический файл сообщает об отсутствии `../erpv2`, это ожидаемое ограничение изолированной среды FREGAT.

