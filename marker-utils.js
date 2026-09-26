// marker-utils.js
function createCustomMarker(weight, isFirst = true) {
    const colors = {
        high: '#fd4221',      // > 10000 кг
        medium: '#FF7400',     // > 5000 кг
        normal: '#FFA459',     // > 3000 кг
        low: '#FFCBA1',        // > 1000 кг
        minimal: '#ffffff'     // ≤ 1000 кг
    };
    
    let bgColor = colors.minimal;
    if (weight > 10000) bgColor = colors.high;
    else if (weight > 5000) bgColor = colors.medium;
    else if (weight > 3000) bgColor = colors.normal;
    else if (weight > 1000) bgColor = colors.low;
    
    const height = isFirst ? 25 : 20;
    const width = 40;
    
    // Создаем canvas для маркера
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height + (isFirst ? 5 : 0);
    const ctx = canvas.getContext('2d');
    
    // Рисуем прямоугольник с закругленными углами
    ctx.fillStyle = bgColor;
    ctx.strokeStyle = '#808080';
    ctx.lineWidth = 1;
    
    // Основной прямоугольник
    ctx.beginPath();
    ctx.moveTo(6, 0);
    ctx.lineTo(width - 6, 0);
    ctx.quadraticCurveTo(width, 0, width, 6);
    ctx.lineTo(width, height - 6);
    ctx.quadraticCurveTo(width, height, width - 6, height);
    ctx.lineTo(6, height);
    ctx.quadraticCurveTo(0, height, 0, height - 6);
    ctx.lineTo(0, 6);
    ctx.quadraticCurveTo(0, 0, 6, 0);
    ctx.closePath();
    
    ctx.fill();
    ctx.stroke();
    
    // Добавляем текст
    ctx.font = 'bold 11px Arial';
    ctx.fillStyle = '#333';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(weight + 'кг', width / 2, height / 2);
    
    // Добавляем треугольник только для первой метки
    if (isFirst) {
        ctx.fillStyle = '#808080';
        ctx.beginPath();
        ctx.moveTo(width/2 - 5, height);
        ctx.lineTo(width/2 + 5, height);
        ctx.lineTo(width/2, height + 5);
        ctx.closePath();
        ctx.fill();
    }
    
    return canvas.toDataURL();
}