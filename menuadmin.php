<?php
// menu.php - Выдвигающееся меню с ярлычком на левом краю
?>
<!-- Ярлычок для открытия меню -->
<div class="menu-tab" id="menuTab">
    <span>≡</span>
</div>

<div class="menu-nav show" id="menuNav">
    <div class="menu-header">
        <h3>ОБЩЕЕ</h3>
        <button class="menu-close" id="menuClose">&times;</button>
    </div>
    <ul class="menu-list">
        <li class="menu-item">
            <a href="index22.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'index22.php' ? 'active' : '' ?>">
                База заявок ФЭО
            </a>
        </li>
        <li class="menu-item">
            <a href="available.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'available.php' ? 'active' : '' ?>">
                Доступно к вывозу
            </a>
        </li>
        <li class="menu-item">
            <a href="order.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'order.php' ? 'active' : '' ?>">
                Маршруты и стоимость
            </a>
        </li>		
        <li class="menu-item">
            <a href="flights.php " class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'flights.php ' ? 'active' : '' ?>">
                Рейсы
            </a>
        </li>
        </li>		
        <li class="menu-item">
            <a href="map2.php" target="_blank" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'map2.php' ? 'active' : '' ?>">
                Карта
            </a>
        </li>
		        <li class="menu-item">
            <a href="PlanFact2.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'PlanFact2.php' ? 'active' : '' ?>">
                Планирование для Фрегата
            </a>
        </li>
		<div class="menu-header">
		<h3>СПРАВОЧНИКИ</h3>
		 </div>
	     <li class="menu-item">
            <a href="contractors.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'contractors.php' ? 'active' : '' ?>">
                Подрядчики
            </a>
        </li>
	     <li class="menu-item">
            <a href="drivers.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'drivers.php' ? 'active' : '' ?>">
                Автотранспорт
            </a>
        </li>
	<li class="menu-item">
            <a href="tracker.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'tracker.php' ? 'active' : '' ?>">
                Мобильные треккеры
            </a>
        </li>
			<li class="menu-item">
            <a href="slitex2.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'slitex2.php' ? 'active' : '' ?>">
                Мобильные треккеры SLITEX
            </a>
        </li>
		        <li class="menu-item">
            <a href="plans.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'plans.php' ? 'active' : '' ?>">
                Планирование
            </a>
        </li>
		<div class="menu-header">
		<h3>ОБЩИЕ ФАЙЛЫ</h3>
		 </div>
		 <li class="menu-item">
            <a href="https://docs.google.com/spreadsheets/d/1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4/edit?gid=0#gid=0" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'https://docs.google.com/spreadsheets/d/1e-iA425O2mhvF12WDnH_lpd6Z3HbCgBOtYtWuF-F6Z4/edit?gid=0#gid=0' ? 'active' : '' ?>">
                Прозвон
            </a>
        </li>
			 <li class="menu-item">
            <a href="syncD.php" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'syncD.php' ? 'active' : '' ?>">
                Синхронизация БД и GOOGLE
            </a>
        </li>
		<li class="menu-item">
            <a href="https://disk.yandex.ru/edit/d/68j8vD6VBZhjizCWpEfxiiPegnqahzm72s0qoIz-cKg6S0QyZzl1QmRKQQ?sk=y6bed655bd5204d8f2ff97c1ba2490312" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'https://disk.yandex.ru/edit/d/68j8vD6VBZhjizCWpEfxiiPegnqahzm72s0qoIz-cKg6S0QyZzl1QmRKQQ?sk=y6bed655bd5204d8f2ff97c1ba2490312' ? 'active' : '' ?>">
                Старая общая
            </a>
        </li>

		<li class="menu-item">
            <a href="https://disk.yandex.ru/d/SVyHNWLzSoklPA" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'https://disk.yandex.ru/d/SVyHNWLzSoklPA' ? 'active' : '' ?>">
                Папка с данными водителей
            </a>
        </li>
		
		<li class="menu-item">
            <a href="https://docs.google.com/spreadsheets/d/1CrOsduVPy7KA4iee9Y5O0ycIru_C270UOOnkfWumv-U/edit?usp=sharing" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'https://docs.google.com/spreadsheets/d/1CrOsduVPy7KA4iee9Y5O0ycIru_C270UOOnkfWumv-U/edit?usp=sharing' ? 'active' : '' ?>">
                Сводный фаил
            </a>
        </li>
				<li class="menu-item">
            <a href="https://docs.google.com/spreadsheets/d/1f36iE219LuPTnpsT4l-geHSjErOVRsMrKltcNKinPW4/edit?usp=sharing" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'https://docs.google.com/spreadsheets/d/1f36iE219LuPTnpsT4l-geHSjErOVRsMrKltcNKinPW4/edit?usp=sharing' ? 'active' : '' ?>">
                Кастомный треккер
            </a>
        </li>
				<div class="menu-header">
		<h3>ИНТЕРФЕЙС ЛОГИСТОВ</h3>
		</div>
		<li class="menu-item">
            <a href="timeline.php" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'timeline.php' ? 'active' : '' ?>">
                График вывоза
            </a>
        </li>
				<li class="menu-item">
            <a href="http://spugovxsim.temp.swtest.ru/fregat/feo/work.php" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'http://spugovxsim.temp.swtest.ru/fregat/feo/work.php' ? 'active' : '' ?>">
                Рейсы
            </a>
        </li>
		<li class="menu-item">
            <a href="http://spugovxsim.temp.swtest.ru/fregat/feo/map2.php" target="_blank"  class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'http://spugovxsim.temp.swtest.ru/fregat/feo/map2.php' ? 'active' : '' ?>">
                Карта
            </a>
        </li>
	<div class="menu-header">
        <h3>ТЕХ. ДАННЫЕ</h3>
        
    </div>
	        </li>
			<li class="menu-item">
            <a href="check_duplicate_zayavki.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'check_duplicate_zayavki.php' ? 'active' : '' ?>">
                Поиск дубликатов 
            </a>
        </li>
			<li class="menu-item">
            <a href="status.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'status.php' ? 'active' : '' ?>">
                Статусы заявок
            </a>
        </li>
			<li class="menu-item">
            <a href="overdue.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'overdue.php' ? 'active' : '' ?>">
                Просроченные заявки
            </a>
        </li>
		        </li>
			<li class="menu-item">
            <a href="users.php" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
                Пользователи
            </a>
        </li>
        </li>
		        </li>
			<li class="menu-item">
            <a href="map_files/admin/max_admin.php" target="_blank" class="menu-link <?= basename($_SERVER['PHP_SELF']) == 'map_files/admin/max_admin.php' ? 'active' : '' ?>">
                Админки
            </a>
        </li>		
    </ul>
</div>

<style>
/* Дополнительные стили только для ярлычка, так как его нет в style.css */
.menu-tab {
    position: fixed;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 24px;
    height: 48px;
    background: #0969da; /* цвет как у кнопок btn-primary */
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 0 8px 8px 0;
    box-shadow: 2px 2px 6px rgba(0,0,0,0.15);
    transition: all 0.2s ease;
    z-index: 999;
    font-size: 18px;
    font-weight: bold;
}

.menu-tab:hover {
    width: 28px;
    background: #0a58ca; /* цвет при наведении как у кнопок */
}

/* Анимация появления ярлычка */
@keyframes slideIn {
    from {
        left: -24px;
    }
    to {
        left: 0;
    }
}

.menu-tab {
    animation: slideIn 0.3s ease-out;
}
</style>

<script>
// JavaScript для управления меню
document.addEventListener('DOMContentLoaded', function() {
    const menuTab = document.getElementById('menuTab');
    const menuNav = document.getElementById('menuNav');
    const menuClose = document.getElementById('menuClose');
    
    if (menuTab && menuNav && menuClose) {
        // Открытие меню по клику на ярлычок
        menuTab.addEventListener('click', function(e) {
            e.stopPropagation();
            menuNav.classList.add('show');
        });
        
        // Закрытие меню только по крестику
        menuClose.addEventListener('click', function() {
            menuNav.classList.remove('show');
        });
        
        // Убираем закрытие по клику вне меню
        // Клик мимо меню больше не закрывает его
    }
});
</script>
