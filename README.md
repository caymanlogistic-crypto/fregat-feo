# LEGACY FREGAT FEO

Изолированная локальная копия старой системы FREGAT FEO. Этот репозиторий не является кодовой базой новой ERP PLANEX.

## Быстрый запуск

1. Скопировать `.env.example` в `.env` и указать абсолютный путь `FEO_SQL_DUMP`.
2. Скопировать безопасные примеры локальных конфигураций:
   - `config.example.php` → `config.php`;
   - `include/config.example.php` → `include/config.php`;
   - `map_files/config.example.php` → `map_files/config.php`;
   - `map_files/config/max_notify.example.php` → `map_files/config/max_notify.php`;
   - `glonass/config.example.php` → `glonass/config.php`.
3. Запустить `docker compose up --build -d`.
4. Открыть `http://localhost:8080/fregat/feo/map2.php`.

Дамп импортируется MySQL автоматически только при первом создании тома `feo_mysql_data`.

## Параллельная работа

После baseline-коммита каждая задача получает собственные `TASK_ID`, ветку и linked worktree:

```powershell
$TaskId = "short-task-name"
git worktree add ".worktrees/$TaskId" -b "codex/$TaskId" main
```

- Один worktree — одна задача и одна ветка. Не изменяйте один файл одновременно из разных worktree.
- Каталог `.worktrees/` не попадает в Git.
- Локальный runtime имеет одного владельца за раз: Compose project строго `fregat-feo`, а порт берётся из локального `.env` только после проверки его свободы.
- Перед запуском контейнеров из worktree убедитесь, что контейнеры `fregat-feo` не принадлежат другой активной задаче.
- Для завершённой задачи сначала объедините проверенный commit, затем удалите linked worktree командой `git worktree remove .worktrees/<TASK_ID>`.
- Для реально одновременных runtime-задач нужны отдельные Compose project, port, DB и volume; это настраивается только отдельной командой OWNER.

## Безопасность

В Git запрещено добавлять `.env`, `config.php`, SQL-дампы, логи, серверные архивы, `venv` и `map_test`. Внешние API отключены, пока соответствующий токен явно не задан в `.env`.

## Проверки

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
docker compose config
docker compose ps
```
