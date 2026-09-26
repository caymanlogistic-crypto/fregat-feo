<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

maxAdminRequireAuthJson();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    maxAdminJsonOut(['success' => false, 'error' => 'Database connection error']);
}

function stColumns(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ui_static_texts');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];
        foreach ((array)$rows as $row) {
            $f = (string)($row['Field'] ?? '');
            if ($f !== '') {
                $map[$f] = true;
            }
        }
        $cache = $map;
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function stReady(PDO $pdo): bool
{
    return !empty(stColumns($pdo));
}

function stSeedCatalog(): array
{
    return [
        ['key' => 'route.modal.title', 'category' => 'routes', 'name' => 'Заголовок модального окна рейса', 'description' => 'Шапка формы редактирования рейса', 'text' => 'Редактирование рейса #{id}', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'common.loading', 'category' => 'system', 'name' => 'Загрузка', 'description' => 'Универсальная подпись загрузки', 'text' => 'Загрузка...', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'button.refresh_transport_title', 'category' => 'buttons', 'name' => 'Tooltip обновить транспорт', 'description' => 'Tooltip кнопки обновления транспорта', 'text' => 'Обновить позиции транспорта', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'button.refresh_transport', 'category' => 'buttons', 'name' => 'Кнопка обновить транспорт', 'description' => 'Текст кнопки обновления транспорта', 'text' => 'Обновить транспорт', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'routes.title.planned', 'category' => 'routes', 'name' => 'Заголовок планируемых', 'description' => 'Левая колонка', 'text' => 'Планируемые маршруты', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'routes.title.found', 'category' => 'routes', 'name' => 'Заголовок сформированных', 'description' => 'Левая колонка', 'text' => 'Сформированные рейсы', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'routes.title.started', 'category' => 'routes', 'name' => 'Заголовок started', 'description' => 'Левая колонка', 'text' => 'Вывоз начался', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'layers.title', 'category' => 'routes', 'name' => 'Заголовок управления слоями', 'description' => 'Панель слоев', 'text' => 'Управление слоями', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'layers.available', 'category' => 'routes', 'name' => 'Чекбокс доступно к вывозу', 'description' => 'Панель слоев', 'text' => 'Доступно к вывозу', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'selection.title', 'category' => 'routes', 'name' => 'Заголовок выделенных заявок', 'description' => 'Правая панель', 'text' => 'Выделенные заявки', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'selection.stats.default', 'category' => 'routes', 'name' => 'Дефолт статистики заявок', 'description' => 'Правая панель', 'text' => 'Заявок: 0 • Адресов: 0', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'selection.weight.default', 'category' => 'routes', 'name' => 'Дефолт общего веса', 'description' => 'Правая панель', 'text' => 'Общий вес: 0 кг', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'selection.route.create', 'category' => 'routes', 'name' => 'Статус создания маршрута', 'description' => 'Правая панель', 'text' => 'Создание нового маршрута', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.select.placeholder', 'category' => 'drivers', 'name' => 'Placeholder выбора водителя', 'description' => 'Combobox водителей', 'text' => 'Выберите водителя', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'validation.pick_one_request', 'category' => 'errors', 'name' => 'Валидация: выберите заявку', 'description' => 'Сообщение валидации', 'text' => 'Выберите хотя бы одну заявку', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'confirm.delete_route', 'category' => 'popup', 'name' => 'Confirm удаления маршрута', 'description' => 'Подтверждение удаления', 'text' => 'Удалить маршрут?', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.status.planned', 'category' => 'routes', 'name' => 'Статус планируемый', 'description' => 'Текст статуса planned_route', 'text' => 'Планируемый маршрут', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.status.found', 'category' => 'routes', 'name' => 'Статус сформирован', 'description' => 'Текст статуса found', 'text' => 'Рейс сформирован', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.status.started', 'category' => 'routes', 'name' => 'Статус started', 'description' => 'Текст статуса started', 'text' => 'Вывоз начался', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.new.title', 'category' => 'drivers', 'name' => 'Заголовок нового водителя', 'description' => 'Mini-modal создания водителя', 'text' => 'Новый водитель', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.field.full_name.hint', 'category' => 'hints', 'name' => 'Подсказка по ФИО', 'description' => 'Подсказка под полем ФИО', 'text' => 'Формат: Иванов Иван Иванович', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.field.plate.hint', 'category' => 'hints', 'name' => 'Подсказка по госномеру', 'description' => 'Подсказка под полем госномера', 'text' => 'Формат: А123АА45 или А123АА456', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.new.title', 'category' => 'warehouses', 'name' => 'Заголовок нового склада', 'description' => 'Mini-modal создания склада', 'text' => 'Новый склад', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'button.save', 'category' => 'buttons', 'name' => 'Кнопка сохранить', 'description' => 'Базовая кнопка', 'text' => 'Сохранить', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'button.cancel', 'category' => 'buttons', 'name' => 'Кнопка отмена', 'description' => 'Базовая кнопка', 'text' => 'Отмена', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.comment', 'category' => 'routes', 'name' => 'Поле комментария рейса', 'description' => 'Подпись поля комментарий/заголовок', 'text' => 'Комментарий / заголовок', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.driver', 'category' => 'routes', 'name' => 'Поле водителя рейса', 'description' => 'Подпись поля водитель/машина', 'text' => 'Водитель / машина', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.planned_dates', 'category' => 'routes', 'name' => 'Подпись плановых дат', 'description' => 'Заголовок блока плановых дат', 'text' => 'Вывоз запланирован на даты', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.actual_dates', 'category' => 'routes', 'name' => 'Подпись фактических дат', 'description' => 'Заголовок блока фактических дат', 'text' => 'Фактические даты перевозки', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_found.desc', 'category' => 'routes', 'name' => 'Описание перехода в сформированные', 'description' => 'Helper-текст возле кнопки формирования', 'text' => 'После перевода рейс считается согласованным. Будут зафиксированы водитель, даты, стоимость и заявки. В MAX отправится уведомление, начнётся подготовка транспортных документов.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_found.title', 'category' => 'routes', 'name' => 'Заголовок действия в сформированные', 'description' => 'Заголовок карточки действия', 'text' => 'Сформировать рейс', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_found.button', 'category' => 'buttons', 'name' => 'Кнопка сформировать рейс', 'description' => 'Кнопка перехода planned→found', 'text' => 'Сформировать рейс', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_started.desc', 'category' => 'routes', 'name' => 'Описание начала выполнения', 'description' => 'Helper-текст возле кнопки начала выполнения', 'text' => 'Рейс перейдёт в статус «Вывоз начался». Подключается контроль выполнения перевозки и логика трекера. В MAX будет отправлено уведомление.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_started.title', 'category' => 'routes', 'name' => 'Заголовок действия начала выполнения', 'description' => 'Заголовок карточки действия', 'text' => 'Начало выполнения', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_started.button', 'category' => 'buttons', 'name' => 'Кнопка подтвердить начало вывоза', 'description' => 'Кнопка перехода found→started', 'text' => 'Подтвердить начало вывоза', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.back_to_planned.desc', 'category' => 'routes', 'name' => 'Описание возврата в планирование', 'description' => 'Helper-текст возле кнопки возврата в планирование', 'text' => 'Рейс будет возвращён в планирование. Подготовку документов нужно проверить или приостановить. В MAX будет отправлено уведомление.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.back_to_planned.title', 'category' => 'routes', 'name' => 'Заголовок возврата в планирование', 'description' => 'Заголовок карточки действия', 'text' => 'Возврат в планирование', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.back_to_planned.button', 'category' => 'buttons', 'name' => 'Кнопка вернуть в планируемые', 'description' => 'Кнопка перехода found→planned', 'text' => 'Вернуть в планируемые', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.to_completed.desc', 'category' => 'routes', 'name' => 'Описание завершения рейса', 'description' => 'Helper-текст возле кнопки завершения', 'text' => 'После завершения рейс будет переведен в архив перевозок.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.back_to_found.desc', 'category' => 'routes', 'name' => 'Описание возврата в сформированные', 'description' => 'Helper-текст возле кнопки возврата в сформированные', 'text' => 'Рейс будет возвращён из выполнения в статус «Рейс сформирован». В MAX будет отправлено уведомление.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.action.delete.desc', 'category' => 'routes', 'name' => 'Описание удаления маршрута', 'description' => 'Helper-текст возле кнопки удаления', 'text' => 'Маршрут будет удалён из списка планируемых рейсов.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.edit.select_required', 'category' => 'validation', 'name' => 'Ошибка выбора рейса', 'description' => 'Показ при попытке редактировать без активной карточки', 'text' => 'Выберите рейс для редактирования.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.edit.load_fresh_failed', 'category' => 'validation', 'name' => 'Ошибка загрузки свежих данных рейса', 'description' => 'Показ при сбое получения актуальных данных рейса перед открытием формы редактирования', 'text' => 'Не удалось загрузить актуальные данные рейса. Повторите попытку.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.validation.required.route_type', 'category' => 'validation', 'name' => 'Обязательное поле: тип рейса', 'description' => 'Текст валидации при строгой проверке переходов статуса', 'text' => 'тип рейса', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.validation.required.warehouse_source', 'category' => 'validation', 'name' => 'Обязательное поле: склад отправления', 'description' => 'Текст валидации для складских маршрутов при строгой проверке', 'text' => 'склад отправления', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.validation.required.warehouse_destination', 'category' => 'validation', 'name' => 'Обязательное поле: склад назначения', 'description' => 'Текст валидации для складских маршрутов при строгой проверке', 'text' => 'склад назначения', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.validation.must_select_from_list', 'category' => 'validation', 'name' => 'Ошибка выбора водителя', 'description' => 'Показ, если введён текст, но водитель не выбран из списка', 'text' => 'Выберите водителя из списка.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.validation.full_name', 'category' => 'validation', 'name' => 'Ошибка ФИО водителя', 'description' => 'Inline валидация ФИО в форме нового водителя', 'text' => 'Введите ФИО полностью: Фамилия Имя Отчество', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.validation.plate', 'category' => 'validation', 'name' => 'Ошибка госномера', 'description' => 'Inline валидация госномера в форме нового водителя', 'text' => 'Введите госномер в формате А123АА45 или А123АА456', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.search.not_found', 'category' => 'drivers', 'name' => 'Пустой результат поиска водителей', 'description' => 'Текст в выпадающем меню при отсутствии совпадений', 'text' => 'Ничего не найдено', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.validation.required', 'category' => 'validation', 'name' => 'Ошибка обязательных полей склада', 'description' => 'Ошибка для формы нового склада', 'text' => 'Заполните обязательные поля: название и полный адрес склада.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.validation.latitude', 'category' => 'validation', 'name' => 'Ошибка широты', 'description' => 'Ошибка для некорректной широты склада', 'text' => 'Некорректная широта.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.validation.longitude', 'category' => 'validation', 'name' => 'Ошибка долготы', 'description' => 'Ошибка для некорректной долготы склада', 'text' => 'Некорректная долгота.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.save.failed', 'category' => 'warehouses', 'name' => 'Ошибка сохранения склада', 'description' => 'Ошибка в форме нового склада при неуспешном ответе', 'text' => 'Не удалось сохранить склад.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.save.network_error', 'category' => 'warehouses', 'name' => 'Сетевая ошибка сохранения склада', 'description' => 'Ошибка в форме нового склада при недоступной сети', 'text' => 'Ошибка сети при сохранении склада.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.geocode.address_required', 'category' => 'warehouses', 'name' => 'Ошибка адреса геокодирования', 'description' => 'Показ, если адрес склада не заполнен для геокодирования', 'text' => 'Введите полный адрес для определения координат.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.geocode.loading', 'category' => 'warehouses', 'name' => 'Статус геокодирования', 'description' => 'Текст кнопки во время геокодирования', 'text' => 'Определение...', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.geocode.success', 'category' => 'warehouses', 'name' => 'Успех геокодирования', 'description' => 'Подтверждение успешного определения координат', 'text' => 'Координаты определены.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.geocode.network_error', 'category' => 'warehouses', 'name' => 'Сетевая ошибка геокодирования', 'description' => 'Ошибка сети при запросе координат', 'text' => 'Ошибка сети при определении координат.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.geocode.changed_after_edit', 'category' => 'warehouses', 'name' => 'Подсказка изменения адреса', 'description' => 'Подсказка после изменения адреса после геокодирования', 'text' => 'Адрес изменён, координаты лучше определить заново.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'common.copied', 'category' => 'hints', 'name' => 'Сообщение скопировано', 'description' => 'Подтверждение копирования текста', 'text' => 'Текст скопирован.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'common.copy_failed', 'category' => 'errors', 'name' => 'Ошибка копирования', 'description' => 'Ошибка при копировании текста в буфер обмена', 'text' => 'Не удалось скопировать текст.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.field.full_name.label', 'category' => 'drivers', 'name' => 'Подпись поля ФИО', 'description' => 'Подпись поля ФИО в mini-modal водителя', 'text' => 'ФИО *', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.field.full_name.placeholder', 'category' => 'drivers', 'name' => 'Placeholder поля ФИО', 'description' => 'Placeholder для поля ФИО в mini-modal водителя', 'text' => 'Иванов Иван Иванович', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.select.open_list', 'category' => 'drivers', 'name' => 'Открыть список водителей', 'description' => 'ARIA label кнопки раскрытия списка водителей', 'text' => 'Открыть список', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'common.from_short', 'category' => 'forms', 'name' => 'Короткая подпись «С»', 'description' => 'Подпись начала диапазона дат', 'text' => 'С', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'common.to_short', 'category' => 'forms', 'name' => 'Короткая подпись «По»', 'description' => 'Подпись конца диапазона дат', 'text' => 'По', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.create.button', 'category' => 'buttons', 'name' => 'Кнопка создать водителя', 'description' => 'Текст кнопки создания водителя', 'text' => 'Создать водителя', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.create.in_progress', 'category' => 'drivers', 'name' => 'Создание водителя (прогресс)', 'description' => 'Текст кнопки во время создания водителя', 'text' => 'Создание...', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.failed', 'category' => 'errors', 'name' => 'Ошибка создания водителя', 'description' => 'Сообщение при неуспешном ответе create_driver', 'text' => 'Не удалось создать водителя.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.server_no_driver', 'category' => 'errors', 'name' => 'Сервер не вернул водителя', 'description' => 'Сообщение если create_driver вернул success без объекта driver', 'text' => 'Сервер не вернул данные водителя.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.existing_selected', 'category' => 'drivers', 'name' => 'Существующий водитель выбран', 'description' => 'Сообщение при duplicate-driver защите', 'text' => 'Такой водитель уже существует и выбран в форме.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.new_tracker_done', 'category' => 'drivers', 'name' => 'Трекер настроен', 'description' => 'Первая строка результата при new_mobile_tracker', 'text' => 'Трекер настроен.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.free_left', 'category' => 'drivers', 'name' => 'Осталось свободных трекеров', 'description' => 'Префикс строки со счётчиком свободных трекеров', 'text' => 'Свободных трекеров осталось', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.retranslation_done', 'category' => 'drivers', 'name' => 'Ретрансляция отправлена в MAX', 'description' => 'Результат создания водителя с типом retranslation', 'text' => 'Водитель создан и выбран в форме. Настройки ретрансляции отправлены в MAX.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.create.network_error', 'category' => 'errors', 'name' => 'Сетевая ошибка создания водителя', 'description' => 'Сообщение при fetch error в create_driver', 'text' => 'Ошибка сети при создании водителя.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.gps.helper.retranslation', 'category' => 'hints', 'name' => 'Подсказка GPS: ретрансляция', 'description' => 'Информирование о режиме retranslation в форме нового водителя', 'text' => 'Для варианта «Ретрансляция» используется тот же алгоритм регистрации трекера. Различается только текст MAX-уведомления.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.gps.helper.new_mobile', 'category' => 'hints', 'name' => 'Подсказка GPS: новый мобильный трекер', 'description' => 'Информирование о режиме new_mobile_tracker в форме нового водителя', 'text' => 'Для варианта «Новый мобильный трекер» система подберет свободный трекер SLITEX и зарегистрирует его после сохранения водителя.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.workflow.update.title', 'category' => 'routes', 'name' => 'Актуализация рейса', 'description' => 'Заголовок блока обновления рейса в модальном окне', 'text' => 'Актуализация рейса', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.workflow.update.desc', 'category' => 'routes', 'name' => 'Описание актуализации рейса', 'description' => 'Описание блока обновления рейса в модальном окне', 'text' => 'Изменения дат автоматически фиксируются в МАКС.', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.workflow.update.desc.found', 'category' => 'routes', 'name' => 'Описание актуализации рейса (сформирован)', 'description' => 'Описание блока обновления рейса в статусе "Рейс сформирован"', 'text' => 'Изменения автоматически фиксируются в МАКС.', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'route.action.save_update.button', 'category' => 'buttons', 'name' => 'Сохранить/Обновить рейс', 'description' => 'Кнопка сохранения изменений в модальном окне рейса', 'text' => 'Сохранить/Обновить', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.cost', 'category' => 'forms', 'name' => 'Поле Стоимость', 'description' => 'Подпись поля стоимости в форме рейса', 'text' => 'Стоимость', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.route_type', 'category' => 'forms', 'name' => 'Поле Тип рейса', 'description' => 'Подпись поля типа рейса в форме рейса', 'text' => 'Тип рейса', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.field.requests', 'category' => 'forms', 'name' => 'Поле Список заявок', 'description' => 'Подпись поля списка заявок в форме рейса', 'text' => 'Список заявок', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.source', 'category' => 'warehouses', 'name' => 'Склад отправления', 'description' => 'Подпись поля склада отправления в форме рейса', 'text' => 'Склад отправления', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.destination', 'category' => 'warehouses', 'name' => 'Склад назначения', 'description' => 'Подпись поля склада назначения в форме рейса', 'text' => 'Склад назначения', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.name.required', 'category' => 'warehouses', 'name' => 'Название склада *', 'description' => 'Обязательная подпись поля названия склада', 'text' => 'Название склада *', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.address.required', 'category' => 'warehouses', 'name' => 'Полный адрес *', 'description' => 'Обязательная подпись поля полного адреса склада', 'text' => 'Полный адрес *', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.button.geocode', 'category' => 'buttons', 'name' => 'Кнопка Определить координаты', 'description' => 'Кнопка геокодирования в форме склада', 'text' => 'Определить координаты', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.latitude', 'category' => 'warehouses', 'name' => 'Широта', 'description' => 'Подпись поля широты склада', 'text' => 'Широта', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.field.longitude', 'category' => 'warehouses', 'name' => 'Долгота', 'description' => 'Подпись поля долготы склада', 'text' => 'Долгота', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'warehouse.marker.label', 'category' => 'map', 'name' => 'Текст маркера склада', 'description' => 'Короткая надпись в чёрном маркере склада', 'text' => 'СКЛАД', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.popup.name', 'category' => 'map', 'name' => 'Название в popup склада', 'description' => 'Подпись поля названия в popup склада', 'text' => 'Название', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.popup.address', 'category' => 'map', 'name' => 'Адрес в popup склада', 'description' => 'Подпись поля адреса в popup склада', 'text' => 'Адрес', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.popup.coordinates', 'category' => 'map', 'name' => 'Координаты в popup склада', 'description' => 'Подпись поля координат в popup склада', 'text' => 'Координаты', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.validation.structure', 'category' => 'errors', 'name' => 'Структура таблицы складов не поддерживается', 'description' => 'Ответ save_warehouse при неподдерживаемой структуре таблицы', 'text' => 'Структура таблицы складов не поддерживается.', 'usage' => 'map_files/save_warehouse.php'],
        ['key' => 'warehouse.save.error_generic', 'category' => 'errors', 'name' => 'Общая ошибка сохранения склада', 'description' => 'Fallback-ошибка save_warehouse в catch', 'text' => 'Ошибка сохранения склада.', 'usage' => 'map_files/save_warehouse.php'],
        ['key' => 'max.test.success', 'category' => 'max', 'name' => 'Успех теста MAX', 'description' => 'Служебное сообщение статуса', 'text' => 'Тест отправлен', 'usage' => 'map_files/admin/max_event_center.php'],
    ];
}

function stDefaultByKey(string $key): ?array
{
    foreach (stSeedCatalog() as $row) {
        if ((string)($row['key'] ?? '') === $key) {
            return $row;
        }
    }
    return null;
}

function stSaveRow(PDO $pdo, array $row): void
{
    $cols = stColumns($pdo);
    $set = [];
    $params = [];
    $put = static function(string $column, $value) use (&$set, &$params, $cols): void {
        if (!isset($cols[$column])) return;
        $set[] = "`{$column}` = :{$column}";
        $params[":{$column}"] = $value;
    };
    $put('text_key', (string)($row['key'] ?? ''));
    $put('key_name', (string)($row['key'] ?? ''));
    $put('key', (string)($row['key'] ?? ''));
    $put('category', (string)($row['category'] ?? 'system'));
    $put('title', (string)($row['name'] ?? ''));
    $put('name', (string)($row['name'] ?? ''));
    $put('description', (string)($row['description'] ?? ''));
    $put('text_value', (string)($row['text'] ?? ''));
    $put('text', (string)($row['text'] ?? ''));
    $put('usage_path', (string)($row['usage'] ?? ''));
    $put('used_in', (string)($row['usage'] ?? ''));

    if (empty($set)) {
        return;
    }

    $keyColumn = isset($cols['text_key']) ? 'text_key' : (isset($cols['key_name']) ? 'key_name' : (isset($cols['key']) ? 'key' : ''));
    if ($keyColumn === '') {
        throw new RuntimeException('Не найден ключевой столбец ui_static_texts');
    }

    $sql = 'INSERT INTO ui_static_texts SET ' . implode(', ', $set) . ' ON DUPLICATE KEY UPDATE ';
    $updates = [];
    foreach ($set as $assign) {
        [$left] = explode('=', $assign, 2);
        $left = trim($left);
        if ($left === "`{$keyColumn}`") continue;
        $updates[] = $left . ' = VALUES(' . $left . ')';
    }
    if (empty($updates)) {
        $updates[] = "`{$keyColumn}` = VALUES(`{$keyColumn}`)";
    }
    $sql .= implode(', ', $updates);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function stLoadRows(PDO $pdo, string $q = ''): array
{
    if (!stReady($pdo)) {
        return [];
    }
    $cols = stColumns($pdo);
    $keyCol = isset($cols['text_key']) ? 'text_key' : (isset($cols['key_name']) ? 'key_name' : (isset($cols['key']) ? 'key' : ''));
    $textCol = isset($cols['text_value']) ? 'text_value' : (isset($cols['text']) ? 'text' : '');
    $nameCol = isset($cols['title']) ? 'title' : (isset($cols['name']) ? 'name' : '');
    $usageCol = isset($cols['usage_path']) ? 'usage_path' : (isset($cols['used_in']) ? 'used_in' : '');
    if ($keyCol === '' || $textCol === '') {
        return [];
    }

    $where = '';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (`{$keyCol}` LIKE :q OR `{$textCol}` LIKE :q";
        if (isset($cols['category'])) $where .= " OR `category` LIKE :q";
        if ($nameCol !== '') $where .= " OR `{$nameCol}` LIKE :q";
        if (isset($cols['description'])) $where .= " OR `description` LIKE :q";
        $where .= ')';
        $params[':q'] = '%' . $q . '%';
    }

    $select = ["`{$keyCol}` AS text_key", "`{$textCol}` AS text_value"];
    $select[] = isset($cols['category']) ? '`category` AS category' : "'' AS category";
    $select[] = $nameCol !== '' ? "`{$nameCol}` AS title" : "'' AS title";
    $select[] = isset($cols['description']) ? '`description` AS description' : "'' AS description";
    $select[] = $usageCol !== '' ? "`{$usageCol}` AS usage_path" : "'' AS usage_path";
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ui_static_texts ' . $where . ' ORDER BY text_key ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$action = trim((string)maxAdminPost('action'));
if ($action === '') {
    maxAdminJsonOut(['success' => false, 'error' => 'Пустое действие']);
}
if (!stReady($pdo)) {
    maxAdminJsonOut(['success' => false, 'error' => 'Таблица ui_static_texts не найдена']);
}

try {
    if ($action === 'seed_texts') {
        $catalog = stSeedCatalog();
        foreach ($catalog as $row) {
            stSaveRow($pdo, $row);
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Ключевые тексты зарегистрированы']);
    }

    if ($action === 'load_texts') {
        $q = trim((string)maxAdminPost('search', ''));
        maxAdminJsonOut(['success' => true, 'data' => ['rows' => stLoadRows($pdo, $q)]]);
    }

    if ($action === 'save_text') {
        $key = trim((string)maxAdminPost('text_key', ''));
        if ($key === '') {
            throw new RuntimeException('Пустой KEY');
        }
        stSaveRow($pdo, [
            'key' => $key,
            'category' => trim((string)maxAdminPost('category', 'system')),
            'name' => trim((string)maxAdminPost('title', '')),
            'description' => trim((string)maxAdminPost('description', '')),
            'text' => (string)maxAdminPost('text_value', ''),
            'usage' => trim((string)maxAdminPost('usage_path', '')),
        ]);
        if (function_exists('notifyEvent')) {
            notifyEvent('static_text_saved', [
                'text_key' => $key,
                'category' => trim((string)maxAdminPost('category', 'system')),
                'used_in' => trim((string)maxAdminPost('usage_path', '')),
            ], "Static Text обновлён: {$key}");
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Текст сохранен']);
    }

    if ($action === 'restore_default') {
        $key = trim((string)maxAdminPost('text_key', ''));
        if ($key === '') {
            throw new RuntimeException('Пустой KEY');
        }
        $default = stDefaultByKey($key);
        if (!$default) {
            throw new RuntimeException('Для ключа нет default значения');
        }
        stSaveRow($pdo, $default);
        maxAdminJsonOut(['success' => true, 'message' => 'Default восстановлен', 'data' => ['text_value' => (string)$default['text']]]);
    }

    maxAdminJsonOut(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    maxAdminJsonOut(['success' => false, 'error' => $e->getMessage()]);
}
