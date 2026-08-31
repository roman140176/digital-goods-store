-- Склады поставщиков живут в отдельных базах: основное приложение
-- физически не имеет доступа к пулу ключей.
CREATE DATABASE supplier_a;
CREATE DATABASE supplier_b;
