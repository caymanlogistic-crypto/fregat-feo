<?php
// menu.php - Выдвигающееся меню
?>

<style>
/* Ярлычок - всегда поверх всего */
.menu-tab {
    position: fixed;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 24px;
    height: 48px;
    background: #0969da;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 0 8px 8px 0;
    z-index: 10001;
    font-size: 18px;
    font-weight: bold;
    transition: all 0.2s ease;
}

.menu-tab:hover {
    width: 28px;
    background: #0a58ca;
}

/* Меню - поверх панели маршрутов */
.menu-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    background: white;
    box-shadow: 2px 0 8px rgba(0,0,0,0.15);
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    z-index: 10000;
    overflow-y: auto;
}

.menu-nav.show {
    transform: translateX(0);
}

.menu-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #eaeef2;
    background: #f6f8fa;
}

.menu-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.menu-close {
    font-size: 24px;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    color: #57606a;
}

.menu-close:hover {
    color: #000;
}

.menu-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.menu-item {
    border-bottom: 1px solid #eaeef2;
}

.menu-link {
    display: block;
    padding: 12px 20px;
    color: #24292f;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.2s ease;
    /* ФИКС ОТ ПРЫЖКОВ */
    font-weight: normal;
    line-height: 1.4;
}

.menu-link:hover {
    background: #f6f8fa;
}

/* АКТИВНЫЙ ПУНКТ - есть подсветка, НЕТ жирности */
.menu-link.active {
    background: #0969da;
    color: white;
    font-weight: normal;   /* НЕ меняем вес шрифта - нет прыжков */
    line-height: 1.4;      /* такая же высота строки как у обычных */
}
</style>
<!-- Ярлычок -->
<div class="menu-tab" id="menuTab">≡</div>

<!-- Меню -->
<div class="menu-nav" id="menuNav">
    <div class="menu-header">
        <h3>Навигация</h3>
        <button class="menu-close" id="menuClose">×</button>
    </div>
    
    <ul class="menu-list">
        <li><a href="/fregat/feo/indexm.php" class="menu-link">ВСЕ ЗАЯВКИ</a></li>
        <li><a href="/fregat/feo/timeline.php" class="menu-link">График вывоза</a></li>
        <li><a href="/fregat/feo/work.php" class="menu-link">Рейсы</a></li>
        <li><a href="/fregat/feo/map2.php" class="menu-link">Карта</a></li>
    </ul>

    <div class="menu-header">
        <h3>ДЛЯ СВЕТЛАНЫ</h3>
    </div>

    <ul class="menu-list">
        <li><a href="/fregat/feo/syncS.php" class="menu-link">Проверка синхронизации заявок</a></li>
        <li><a href="/fregat/feo/docs2/index.php" class="menu-link">Генерация ТН</a></li>
    </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tab = document.getElementById('menuTab');
    var nav = document.getElementById('menuNav');
    var close = document.getElementById('menuClose');
    
    tab.onclick = function() {
        nav.classList.add('show');
    };
    
    close.onclick = function() {
        nav.classList.remove('show');
    };
    
    document.onclick = function(e) {
        if (!nav.contains(e.target) && e.target !== tab) {
            nav.classList.remove('show');
        }
    };
});
</script>