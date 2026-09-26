# Codex entry point — LEGACY FREGAT FEO

Отвечай OWNER на русском. Это отдельный legacy-проект; он не является частью ERP PLANEX.

## Обязательный старт
1. Полностью прочитай эту страницу Notion: `00 — START HERE — LEGACY FREGAT FEO` (`https://app.notion.com/p/3e7a64edce328181b7d2e4b1875ffdaa`).
2. Прочитай `CODEX_WORKFLOW_RULES.md` только как проектный runtime-регламент. Указанный там старый локальный путь `C:\fregat\map` является историческим; фактический корень проекта — каталог, содержащий этот `AGENTS.md`.
3. Перед изменениями проверь `git status`, активные процессы, порты и Docker Compose project name. Не исправляй глобальную среду ради локальной задачи.
4. Деплой, production-доступ, production-записи и импорт нового production-дампа выполняются только по отдельной прямой команде OWNER.

## Источники истины
- Локальный проект: `C:\Users\Vladimir\Documents\ChatGPT\СТАРАЯ КАРТА 2\fregat-feo`.
- GitHub: приватный репозиторий `caymanlogistic-crypto/fregat-feo`.
- Notion: только хаб `LEGACY FREGAT FEO — старая система`, его стартовая страница и база `LEGACY FEO — задачи` (`collection://7e845459-e925-4232-9866-309a7d214742`).
- Локальный стек: проектные `compose.yaml` и `Dockerfile`, PHP 8.4 Apache, MySQL 5.7/utf8mb4. Порт `8080` допустим только если свободен и принадлежит этому проекту.

## Жёсткая изоляция от ERP PLANEX
- Не открывай, не изменяй и не используй `C:\plan-ex\ERP-PLANEX`, его `source`, `local`, worktrees, evidence, процессы, порты, базы, конфиги и Notion MASTER MEMORY без отдельной cross-project команды OWNER.
- Не используй PLANEX MySQL `127.0.0.1:33316`, порт `8016`, slots 2–4 или чужие динамические порты.
- Не создавай relations, mentions или backlinks в ERP PLANEX Notion и не копируй туда решения или задачи FREGAT.
- Не переустанавливай и не сбрасывай общие PHP, MySQL, Python, Node, Git, Docker, WSL, Windows Services или PATH.
- Запрещены широкие остановки процессов и очистки: `taskkill /IM`, `Stop-Process -Name`, `killall`, `docker system prune`, `docker volume prune`, factory reset и `compose down` для чужого project name.

## Безопасная параллельная работа
- Каждый изменяющий агент владеет только своим `TASK_ID`, веткой/worktree, портом, процессами, логами, evidence и при необходимости отдельной БД/volume.
- До первого проверенного baseline-коммита Git параллельная запись в этот корень запрещена: один агент может изменять файлы, остальные работают только read-only или в отдельной копии. После baseline-коммита используй отдельную ветку и worktree на задачу.
- Перед остановкой процесса или контейнера докажи владение по PID/command line либо Compose project name и task metadata.
- Не используй общий порт по умолчанию, если он занят. Выбери свободный task-local порт и зафиксируй владельца.
- Production-дамп разрешено импортировать только в локальный FREGAT volume; секреты, `.env`, `config.php`, SQL, логи и архивы не добавляются в Git или Notion.

## Рабочий поток
`отдельная задача → read-only диагностика → изолированная ветка/worktree → минимальное изменение → focused checks → один финальный локальный preflight → PR/публикация только по правилам проекта`.

Если локальная возможность отсутствует или Docker CLI зависает, не переустанавливай и не перезапускай общую среду. Останови только собственный диагностический процесс, зафиксируй факт и сообщи OWNER.

При конфликте правил эта страница и актуальная Notion-страница `00 — START HERE — LEGACY FREGAT FEO` имеют приоритет над историческими локальными путями и общими инструкциями в `CODEX_WORKFLOW_RULES.md`.
