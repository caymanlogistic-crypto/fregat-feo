# MAP PROJECT — CODEX / ROO WORKFLOW RULES

## ABSOLUTE FIRST RULE

Перед началом ЛЮБОЙ новой задачи ROO / Codex / агент ОБЯЗАН:

1. Открыть и полностью прочитать этот файл:
   `C:\fregat\map\CODEX_WORKFLOW_RULES.md`
2. Подтвердить в своём первом сообщении:
   `CODEX_WORKFLOW_RULES.md прочитан, работаю по нему.`
3. Только после этого читать код проекта и выполнять задачу.
4. Если файл не прочитан — задачу НЕ начинать.

Этот файл является главным рабочим регламентом проекта.

---

## PROJECT STATUS

Проект успешно прошёл:
- архитектурный рефакторинг;
- runtime stabilization;
- transport visibility filtering;
- planned/found route panels;
- edit-mode implementation;
- production-safe separation;
- warehouse route_type stabilization;
- MAX notification stabilization;
- warehouse_movements base implementation.

Текущая test-ветка считается рабочей.

---

## PROJECT TYPE

Это legacy PHP runtime-first проект.

НЕ:
- SPA;
- React;
- Vue;
- Laravel;
- Symfony;
- Node.js app;
- frontend build system.

Это:
- PHP + vanilla JS + CSS;
- runtime-first architecture;
- dispatcher operational UI;
- shared-hosting compatible system;
- production-safe legacy system.

Главный принцип:

**Predictable boring stability > architectural purity.**

---

## LOCAL PROJECT PATH

Локальный рабочий проект на Windows:

`C:\fregat\map`

Локально Git работает именно здесь.

Локальный PHP CLI вызывается так:

`php`

НЕ использовать локально:

`php8.4`

---

## SERVER PATH

Правильный серверный runtime path:

`/home/s/spugovxsim/public_html/fregat/feo/`

Ошибочный nested path запрещён:

`/home/s/spugovxsim/home/s/spugovxsim/public_html/fregat/feo/`

Любая загрузка или работа во вложенный nested path считается ошибкой.

---

## SERVER IS NOT A GIT REPOSITORY

КРИТИЧЕСКИ ВАЖНО:

Папка на сервере:

`/home/s/spugovxsim/public_html/fregat/feo/`

НЕ является git-репозиторием.

На сервере ЗАПРЕЩЕНО выполнять:

```bash
git pull
git status
git log
git commit
git push
```

Если на сервере команда Git возвращает:

`fatal: Not a git repository`

это НЕ ошибка проекта. Это ожидаемо.

---

## CORRECT DEPLOY WORKFLOW

Правильная схема работы:

1. Локально в `C:\fregat\map`:
   - изменить код;
   - выполнить проверки;
   - `git add`;
   - `git commit`;
   - `git push origin main`.

2. Дождаться GitHub autodeploy.

3. На сервере:
   - НЕ выполнять git-команды;
   - проверять наличие файлов через `ls -la`;
   - выполнять только runtime-команды через `/usr/bin/php8.4`.

Правильные серверные команды:

```bash
cd /home/s/spugovxsim/public_html/fregat/feo
ls -la map_files/Support/warehouse_movements.php
/usr/bin/php8.4 -l map_files/Support/warehouse_movements.php
/usr/bin/php8.4 -l map_files/save_planned_route.php
```

---

## FORBIDDEN DEPLOY METHODS

Запрещено без отдельного разрешения:

- SCP поверх GitHub-autodeploy;
- FTP поверх GitHub-autodeploy;
- SSH one-liner, который делает git-команды на сервере;
- ручное копирование файлов в production без GitHub;
- смешивать local Git workflow и server copy workflow.

Нельзя делать:

```bash
ssh ... "cd /home/s/spugovxsim/public_html/fregat/feo && git pull"
```

Потому что серверная папка не Git repository.

---

## MAIN ENTRYPOINTS

### PRODUCTION

`/public_html/fregat/feo/map2.php`

### TEST / STAGING

`/public_html/fregat/feo/maptest.php`

---

## ABSOLUTE PRODUCTION RULE

`map2.php` — production файл.

Запрещено:
- удалять `map2.php`;
- переименовывать `map2.php`;
- изменять `map2.php`;
- деплоить поверх `map2.php`;
- использовать `map2.php` для тестов;
- ломать production.

Если задача явно не разрешает работу с `map2.php`, агент обязан считать этот файл запрещённым.

---

## WORKING FILES

Разрешено изменять только при необходимости задачи:

`maptest.php`

`map_files/*`

Типовые рабочие файлы:

- `map_files/assets/maptest.js`
- `map_files/save_planned_route.php`
- `map_files/get_planned_routes.php`
- `map_files/Views/panels.php`
- `map_files/Support/max_notify.php`
- `map_files/Support/warehouse_movements.php`
- `map_files/cron/notify_route_control.php`
- `map_files/tools/*`

---

## FORBIDDEN / DANGEROUS FILES

Без отдельного разрешения нельзя изменять:

- `map2.php`
- `menu2.php`
- `config.php`

С осторожностью, только если задача явно требует:

- `save_planned_route.php`
- `get_planned_routes.php`
- `delete_planned_route.php`
- `maptest.php`

---

## JAVASCRIPT FILE RULES

Внешние JS-файлы:

- `maptest.js`
- `map.js`

НЕ МОГУТ содержать:

- `<?php`
- `?>`
- `<?=`
- `<script`
- `</script>`
- HTML
- inline PHP

Весь PHP bootstrap передавать только через:

`window.MAP_BOOTSTRAP`

в:

`map_files/Views/scripts.php`

---

## MAPTEST.JS RULE

Для test-ветки использовать:

`map_files/assets/maptest.js`

НЕ:

`map.js`

`scripts.php` в `maptest.php` должен подключать:

`map_files/assets/maptest.js?v=<filemtime>`

---

## UTF-8 RULES

Все файлы:

- `.php`
- `.js`
- `.css`
- `.md`

обязаны быть:

`UTF-8 without BOM`

Если PowerShell показывает кракозябры — это не доказательство поломки файла. Проверять нужно содержимое и runtime.

---

## STATIC CACHE RULE

Если меняется:

- JS;
- CSS;

использовать versioned URL:

`?v=filemtime(...)`

---

## DATABASE RULES

Запрещено:

- руками делать `UPDATE`, `DELETE`, `INSERT` по рабочим рейсам;
- менять старые рейсы напрямую;
- массово исправлять историю без dry-run;
- удалять legacy-колонки;
- менять enum существующих полей без отдельного разрешения.

Разрешено:

- `SELECT`;
- диагностические read-only скрипты;
- controlled backfill только для тестового рейса с жёсткими защитами;
- добавлять новые таблицы/поля только после подтверждения пользователя.

---

## WAREHOUSE LOGIC RULES

`flights.unload_type` — legacy-поле.

НЕ удалять.
НЕ переименовывать.
НЕ считать главным источником новой логики.

Главное поле направления перевозки:

`flights.route_type`

Текущие значения:

- `generator_to_utilizer` — ОО → Утилизатор
- `generator_to_warehouse` — ОО → Временный склад
- `warehouse_to_warehouse` — Склад → Склад
- `warehouse_to_utilizer` — Склад → Утилизатор

Складские остатки считаются НЕ по `flights.status`, а по:

`warehouse_movements`

Правила движений:

- `generator_to_utilizer` — движений нет;
- `generator_to_warehouse` — `receipt`;
- `warehouse_to_warehouse` — `transfer_out` + `transfer_in`;
- `warehouse_to_utilizer` — `issue`.

PASS по складской логике можно ставить только после фактического `SELECT` из `warehouse_movements`.

Нельзя писать:

`endpoint success значит PASS`

Нужно доказательство:

```sql
SELECT * FROM warehouse_movements WHERE flight_id = <ID>;
```

---

## TEST ROUTE RULES

Если нужен тестовый маршрут:

- название или комментарий обязательно содержит `ТЕСТ` или `TEST`;
- нельзя использовать рабочие рейсы;
- нельзя портить активные заявки;
- все переходы статусов только через штатный UI или штатный endpoint;
- прямой `UPDATE flights SET status=...` запрещён.

---

## SERVER RUNTIME COMMANDS

На сервере использовать PHP только так:

```bash
/usr/bin/php8.4 -l file.php
/usr/bin/php8.4 script.php
```

Не использовать на сервере просто `php`, потому что там может быть старая версия.

---

## LOCAL RUNTIME COMMANDS

На Windows локально использовать PHP так:

```powershell
php -l map_files\save_planned_route.php
php -l map_files\Support\warehouse_movements.php
```

Не использовать локально `php8.4`.

---

## MAX NOTIFICATION RULES

MAX-уведомления не отключать.

Все новые MAX-сообщения должны быть:

- короткие;
- понятные менеджеру;
- без технического мусора;
- без `route_type`, `unload_type`, `generator_to_warehouse` в пользовательском тексте.

Пользовательские термины:

- `ОО → Утилизатор`
- `ОО → Временный склад`
- `Склад → Склад`
- `Склад → Утилизатор`

Для складских сообщений:

- `Груз поступил на временный склад`
- `Груз вывезен со склада на утилизатор`
- `Груз перемещён между складами`

---

## UI/UX RULES

Агент обязан следить не только за кодом, но и за удобством интерфейса.

Формы и сообщения должны быть понятны диспетчеру/менеджеру.

Запрещено показывать пользователю технические значения:

- `generator_to_warehouse`
- `warehouse_to_utilizer`
- `unload_type`
- `OO/SKLAD`

Пользовательские подписи должны быть понятными:

- `Маршрут груза`
- `ОО → Утилизатор`
- `ОО → Временный склад`
- `Склад → Склад`
- `Склад → Утилизатор`

Если агент предлагает UI/UX-улучшение, сначала кратко описать предложение. Вносить в код только после согласования пользователя, если это не часть текущей задачи.

---

## TEMPORARY FILES RULE

Не коммитить временные файлы:

- `db_inspect.php`
- `db_report.txt`
- любые дампы БД;
- файлы с паролями/токенами;
- временные debug-файлы без явного разрешения.

После использования временные файлы на сервере удалить:

```bash
rm -f db_inspect.php
rm -f db_report.txt
```

---

## SECURITY RULES

Запрещено:

- печатать пароли БД в отчёт;
- печатать токены MAX;
- коммитить дампы БД;
- коммитить реальные credentials;
- создавать новый config с паролями;
- раскрывать содержимое `config.php`.

Если нужен доступ к БД — использовать существующий способ подключения проекта.

---

## SSH / TERMINAL RULES

Не открывать бесконечные SSH-сессии.

Если много активных терминалов:

- остановить текущие зависшие команды;
- закрыть лишние терминалы;
- не запускать параллельно 10 проверок;
- не использовать длинные SSH one-liners.

Если сервер недоступен по SSH, это не повод писать ложный PASS.

---

## FAILURE HANDLING / BLOCKED RULES

Если у ROO / Codex / агента что-то не получилось, это считается частью задачи, которую нужно исправить, а не игнорировать.

Запрещено:

- писать `задача завершена`, если ключевая проверка не выполнена;
- подменять факт словами `ожидается`, `по коду должно работать`, `endpoint success значит PASS`;
- перекладывать незавершённую проверку на пользователя без статуса `BLOCKED`;
- скрывать причину ошибки;
- продолжать разработку поверх непроверенного результата.

Если команда, SSH, SELECT, runtime, MAX, push или проверка не выполнены, агент обязан остановиться и написать:

```text
STATUS: BLOCKED
Что не получилось:
Причина или гипотеза:
Какие команды выполнены:
Фактический вывод ошибки:
Что уже проверено:
Что нужно сделать, чтобы разблокировать:
Какое правило нужно добавить в CODEX_WORKFLOW_RULES.md, чтобы это не повторилось:
```

После любого `BLOCKED` агент обязан предложить минимальное исправление процесса или правила. Нельзя просто сказать `не удалось` и идти дальше.

---

## SELF-HEALING WORKFLOW RULE

Если агент столкнулся с повторяющейся проблемой процесса, он обязан предложить правку в этот файл.

Примеры проблем, которые требуют добавления правила:

- агент снова пытается выполнить `git pull` на сервере без `.git`;
- агент пишет PASS без фактического SELECT;
- агент создаёт временные файлы и не удаляет их;
- агент не может выполнить SSH из-за множества терминалов;
- агент просит пользователя сделать то, что должен был сделать сам;
- агент не видит, что сервер работает через GitHub autodeploy;
- агент коммитит лишние файлы;
- агент не проверяет MAX или runtime.

Формат предложения:

```text
Предлагаю добавить правило:
<короткое правило>
Почему:
<какую ошибку это предотвращает>
```

Без согласования пользователя агент не должен сам менять этот файл, но обязан предложить улучшение.

---

## NO FALSE PASS RULE

PASS разрешён только при наличии фактического доказательства.

Для разных типов задач доказательства такие:

- PHP-синтаксис: фактический вывод `php -l`.
- JS-синтаксис: фактический вывод `node --check` или подтверждение браузерной консоли.
- HTTP: фактический HTTP 200/JSON success/Network без 404/500.
- БД: фактический `SELECT` с нужными строками.
- MAX: фактический `notify_success`, лог отправки или запись в очереди/логе.
- Git: фактический commit hash и push status.
- UI: фактическое прохождение сценария или честный статус `WARN`, если UI не проверен глазами.

Нельзя считать доказательством:

- `код выглядит правильно`;
- `функция должна была вызваться`;
- `endpoint success` без проверки побочных эффектов;
- `ожидается 2 строки`;
- `пользователь потом проверит`;
- `SSH не дал выполнить SELECT`, если задача требует SELECT.

Если доказательства нет — итог только `WARN` или `FAIL`, но не `PASS`.

---

## DATABASE PROOF RULES

Для задач, которые меняют или проверяют данные в БД, обязательно фиксировать фактический результат `SELECT`.

Особенно для `warehouse_movements`:

```sql
SELECT * FROM warehouse_movements WHERE flight_id = <ID> ORDER BY id;
```

Для проверки дублей:

```sql
SELECT
  flight_id,
  movement_type,
  warehouse_id,
  zayavka_id,
  COUNT(*) AS cnt
FROM warehouse_movements
WHERE flight_id = <ID>
GROUP BY flight_id, movement_type, warehouse_id, zayavka_id
HAVING COUNT(*) > 1;
```

Если таблица пустая — это не `ожидаемо`, а диагностический факт. Агент обязан найти причину или поставить `FAIL/BLOCKED`.

Если endpoint возвращает `success: True`, но ожидаемые строки в БД не появились, задача считается незавершённой.

---

## AUTODEPLOY SERVER RULES

В этом проекте серверная папка может быть результатом GitHub autodeploy и не содержать `.git`.

Правильная проверка доставки файлов на сервер:

```bash
cd /home/s/spugovxsim/public_html/fregat/feo
ls -la map_files/Support/warehouse_movements.php
ls -la map_files/tools/backfill_test_188.php
/usr/bin/php8.4 -l map_files/Support/warehouse_movements.php
```

Если файла на сервере нет после push:

- не выполнять `git pull` на сервере;
- не считать задачу завершённой;
- поставить `BLOCKED: autodeploy file not delivered`;
- проверить, был ли push в GitHub;
- проверить, правильный ли путь сервера;
- предложить пользователю проверить autodeploy или разрешить ручную доставку файла.

---

## DO NOT DELEGATE AGENT WORK TO USER RULE

Если задача поручена агенту, он должен выполнить её сам в рамках доступных инструментов.

Нельзя завершать задачу отчётом вида:

```text
Пользователю нужно выполнить...
```

Если агент не может выполнить действие из-за доступа, SSH, отсутствия инструмента или автодеплоя, он должен поставить:

```text
STATUS: BLOCKED
```

И указать точную причину.

Пользователь может выполнить ручной шаг только после явного согласования или когда это принципиально невозможно для агента. Даже в этом случае итог задачи остаётся `WARN/BLOCKED`, пока факт не подтверждён.

---

## TEMPORARY TOOLING STRICT RULES

Любой временный технический файл должен иметь понятную судьбу.

Перед созданием временного файла агент обязан указать:

- зачем файл нужен;
- где он будет лежать;
- CLI-only или HTTP;
- какие защиты есть;
- будет ли он удалён или закоммичен.

Временные файлы для БД/диагностики должны быть безопасными:

- CLI-only, если не требуется HTTP;
- без паролей внутри;
- без вывода токенов/паролей;
- без массовых операций;
- с hardcoded test ID, если это backfill тестового рейса;
- с проверкой `ТЕСТ` / `TEST` в названии или комментарии тестового маршрута;
- с защитой от повторного создания дублей.

После завершения агент обязан указать:

```text
Temporary files:
- оставлены: <почему>
- удалены: <какие>
- запрещено коммитить: <какие>
```

Файлы `db_inspect.php` и `db_report.txt` нельзя оставлять на сервере после использования.

---

## SSH SESSION HYGIENE RULE

Если агент видит или сам сообщает о большом количестве активных терминалов/SSH-сессий, например `37 active terminals`, это считается процессной ошибкой.

Действия агента:

1. Остановить запуск новых SSH-команд.
2. Не открывать новые параллельные терминалы.
3. Закрыть/остановить зависшие процессы, если возможно.
4. Выполнять только одну короткую команду за раз.
5. Не использовать длинные SSH one-liners.
6. Если SSH недоступен — поставить `BLOCKED`, а не писать PASS.

Повторяющаяся перегрузка SSH должна приводить к предложению изменить workflow.

---

## RUNTIME TEST URL

После изменения runtime-кода обязательно проверить:

`http://spugovxsim.temp.swtest.ru/fregat/feo/maptest.php`

---

## RUNTIME TEST CHECKLIST

### Browser

- карта открывается;
- панели отображаются;
- markers отображаются;
- transport отображается;
- routes работают;
- filters работают.

### Console

- нет красных ошибок;
- нет `SyntaxError`;
- нет runtime JS errors.

### Network

- нет 404;
- нет 500;
- нет failed fetch.

---

## PHP / JS CHECKLIST

Если менялись PHP:

```bash
/usr/bin/php8.4 -l maptest.php
/usr/bin/php8.4 -l map_files/save_planned_route.php
/usr/bin/php8.4 -l map_files/get_planned_routes.php
/usr/bin/php8.4 -l map_files/Support/warehouse_movements.php
```

Если менялся JS:

```bash
node --check map_files/assets/maptest.js
```

Если node недоступен — проверить браузерную консоль и честно указать это в отчёте.

---

## GIT RULES

Локально:

```powershell
cd C:\fregat\map
git status
git add <changed files>
git commit -m "clear short english message"
git push origin main
git status
git log --oneline -3
```

Не делать:

```powershell
git add .
```

если в проекте есть временные файлы или не относящиеся к задаче изменения.

Перед commit агент обязан проверить `git status --short` и добавить только файлы текущей задачи.

---

## DEBUGGING RULES

Если есть ошибка:

1. Найти root cause.
2. Исправить минимально.
3. Проверить runtime.
4. Не делать workaround вслепую.
5. Не ломать рабочую систему.
6. Не писать PASS без фактического подтверждения.

---

## REPORT FORMAT

После каждой задачи дать отчёт:

1. `CODEX_WORKFLOW_RULES.md прочитан`.
2. Какие файлы изменены.
3. Что исправлено.
4. Какие команды проверки выполнены.
5. Результаты `php -l`.
6. Результат `node --check`, если применимо.
7. HTTP/runtime status.
8. Фактические SQL SELECT результаты, если задача про БД.
9. MAX-уведомления, если задача затрагивала MAX.
10. Commit hash.
11. Push status.
12. Production `map2.php` не изменялся.
13. Итог: PASS / WARN / FAIL.

PASS разрешён только когда есть фактическая проверка результата, а не предположение по коду.

---

## FINAL RULE

Работать маленькими безопасными шагами.

Не переписывать проект.

Не маскировать незавершённую задачу словами “готово”.

Если проверка не выполнена — писать WARN или FAIL, но не PASS.

---

# SSH / SERVER RUNTIME DISCIPLINE RULE

ROO/Codex must understand that server runtime access is fragile and must be treated as a limited resource.

## SSH SESSION LIMIT RULE

If server access starts failing, hanging, timing out, or ROO sees many active terminal/SSH sessions, ROO MUST NOT continue opening new SSH/SCP/terminal commands.

If more than 5 SSH/terminal sessions appear active or server commands become unstable, ROO MUST:

1. Stop creating new SSH/SCP/terminal tasks.
2. Stop retry loops.
3. Do not open new terminals automatically.
4. Return:

STATUS: BLOCKED

5. Explain:
   - what command failed;
   - whether SSH timed out, hung, or returned an error;
   - how many sessions/terminals appear active if known;
   - what server step remains unfinished.
6. Ask the user to close extra ROO/VS Code/SSH terminals or restart the SSH session.
7. After access is restored, run only ONE short server-runtime command at a time.

ROO must never hide SSH failure behind a fake PASS.

## SERVER COMMAND STYLE RULE

Server runtime checks must be short and explicit.

Allowed server commands:

```bash
cd /home/s/spugovxsim/public_html/fregat/feo
ls -la <file>
/usr/bin/php8.4 -l <file>
/usr/bin/php8.4 <cli_script>
curl -I <url>
```

Forbidden server behavior:

- long chained one-liners;
- SCP + SSH mixed deployment unless explicitly approved;
- repeated retry loops;
- background SSH jobs;
- opening many terminals;
- using server as a Git repository.

The server folder:

```text
/home/s/spugovxsim/public_html/fregat/feo
```

is NOT a Git repository.

Git operations are local only:

```text
C:\fregat\map
```

Then GitHub/autodeploy delivers files to the server.

---

# BACKFILL / DIAGNOSTIC TOOLING SECURITY RULE

Backfill tools are potentially dangerous and must be tightly controlled.

## HTTP BACKFILL ENDPOINTS ARE FORBIDDEN

ROO/Codex must NOT create HTTP-accessible backfill endpoints unless the user explicitly approves this in the current task.

Forbidden without explicit approval:

```text
wm_backfill_*.php
backfill_*.php accessible through browser
repair_*.php accessible through browser
fix_*.php accessible through browser
```

Backfill must be CLI-only by default.

A backfill script must start with a CLI guard:

```php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}
```

## BACKFILL SAFETY RULES

Any backfill/test repair script must:

1. Be CLI-only.
2. Have a hardcoded narrow target, unless user explicitly approved a broader scope.
3. Not accept GET/POST parameters.
4. Not run mass updates by default.
5. Check that the target record is test-safe if it is a test script.
6. Print what it will do.
7. Print what it did.
8. Print SELECT proof after execution.
9. Be deleted after use or explicitly documented as intentionally kept.
10. Never be counted as PASS without factual DB rows.

## TEMPORARY TOOL CLEANUP RULE

After a task involving temporary scripts, ROO must report:

- created temporary files;
- whether each file is CLI-only;
- whether it was deleted or intentionally kept;
- why it is safe to keep.

Temporary inspection files must not be committed unless explicitly useful and safe:

```text
db_inspect.php
db_report.txt
wm_backfill_*.php
one-off repair scripts
```

If a temporary HTTP-accessible tool was created accidentally, ROO must propose deleting it immediately.

---

# AGENT FAILURE IS A FIXABLE BUG RULE

If ROO/Codex fails because of environment, SSH, Git, deploy, runtime, DB proof, or missing access, this must be treated as a workflow defect.

ROO must not ignore repeated failures.

For every repeated failure, ROO must propose one new rule or workflow correction to prevent recurrence.

Example:

```text
Problem: ROO tried git pull on production server, but server is not a git repo.
New rule: Server has no .git; Git only local; server runtime only.
```

Example:

```text
Problem: ROO reported PASS without SELECT proof.
New rule: DB PASS requires factual SELECT output.
```

Example:

```text
Problem: ROO opened too many SSH sessions.
New rule: One server command at a time; block when sessions are overloaded.
```

---

# AUTODEPLOY WAIT / VERIFY RULE

After ROO/Codex performs a local `git push origin main`, it MUST NOT immediately report the task as completed.

The project uses GitHub/autodeploy to deliver files to the server folder:

```text
/home/s/spugovxsim/public_html/fregat/feo
```

The server folder is NOT a Git repository, so ROO must NOT use server-side `git pull`.

## Required autodeploy verification cycle

After every successful `git push origin main`, ROO MUST wait for autodeploy and verify server files itself.

ROO must perform up to 3 verification attempts:

1. Wait 10 seconds.
2. Check deployed files on server through `ssh fregat-map`.
3. If files are not present or not updated, wait another 10 seconds.
4. Repeat up to 3 total attempts.

Example:

```powershell
ssh fregat-map "cd /home/s/spugovxsim/public_html/fregat/feo && ls -la map_files/admin/warehouse_route_reclassifier.php && /usr/bin/php8.4 -l map_files/admin/warehouse_route_reclassifier.php"
```

If the task changed multiple files, ROO must check each important changed file with `ls -la` and `php -l` for PHP files.

## Completion rule

ROO may report PASS only after:

- local commit completed;
- local push completed;
- autodeploy verification completed;
- changed files are visible on server;
- PHP syntax checks pass on server for changed PHP files;
- HTTP/runtime check is completed;
- DB tasks have factual SELECT proof when relevant.

## If autodeploy does not finish

If after 3 attempts / about 30 seconds the files are still not updated on server, ROO MUST return:

```text
STATUS: BLOCKED_AUTODEPLOY
```

and include:

- commit hash that was pushed;
- files expected on server;
- exact `ls -la` / `php -l` commands attempted;
- what result was received;
- what server runtime step remains unfinished.

ROO must NOT say "task completed" while autodeploy/SCP/deploy is still "in progress".

## Forbidden wording

The following is not an acceptable final status:

```text
SCP upload in progress
Autodeploy pending
User should check server
Expected result
Should be deployed
```

These statuses must be treated as:

```text
STATUS: BLOCKED_AUTODEPLOY
```

until ROO verifies the server itself.

## No delegation rule

ROO must not shift autodeploy verification to the user unless all 3 verification attempts failed.

Correct behavior:

1. Push.
2. Wait 10 seconds.
3. Check server.
4. Wait 10 seconds.
5. Check server.
6. Wait 10 seconds.
7. Check server.
8. Only then PASS or BLOCKED_AUTODEPLOY.

SCP IS FORBIDDEN BY DEFAULT

ROO must not use SCP unless the user explicitly says:
"разрешаю SCP для этих файлов".

Normal deployment flow:
local commit → local push → GitHub/autodeploy → server runtime verification.

If autodeploy does not deliver files after 3 attempts, ROO must return:
STATUS: BLOCKED_AUTODEPLOY

ROO must not silently switch to SCP.
ROO must not report "task completed" while SCP is running.
ROO must not open multiple SCP/SSH upload jobs.

DEPLOY RESULT PROOF RULE

After any allowed SCP/autodeploy, ROO must prove deployment by:
1. ls -la target file
2. php -l target PHP file
3. curl -I target URL if web-accessible

Without these checks the task is not complete.


!!!!!!!!!!!!!
Even if SCP succeeds, using SCP without explicit user approval is a workflow violation.
ROO must mark this as WARN_UNAPPROVED_SCP and propose cleanup/reconciliation.
Normal delivery remains: local commit → push → autodeploy → ssh runtime verification.