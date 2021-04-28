# import-yml

[![Latest Stable Version](https://poser.pugx.org/frizus/importyml/v)](//packagist.org/packages/frizus/importyml)
[![License](https://poser.pugx.org/frizus/importyml/license)](//packagist.org/packages/frizus/importyml)
[![Total Downloads](https://poser.pugx.org/frizus/importyml/downloads)](//packagist.org/packages/frizus/importyml)

Импорт yml файла в 1С-Битрикс

Установка
---------

```
composer require frizus/importyml
```

## Описание

Скрипт importyml.php поддерживает импорт UTF-8 yml-файла со структурой вида:
```xml
<?xml version="1.0"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd">
<yml_catalog date="2021-04-13 14:15">
  <shop>
    <name>Интернет-магазин Бренд X</name>
    <company>Бренд X</company>
    <url>http://example.com</url>
    <platform>1C-Bitrix</platform>
    <currencies>
      <currency id="RUB" rate="1" />
    </currencies>
    <categories>
      <category id="1">Категория 1</category>
      <category id="2" parentId="1">Подкатегория 1</category>
      ...
    </categories>
    <offers>
      <offer id="1" available="true">
        <url>https://example.com/product-id1/</url>
        <price>500</price>
        <oldprice>600</oldprice>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
        <picture>http://example.com/images/5bwa35naw35n.jpg</picture>
        <name>Товар</name>
        <description>Описание товара</description>
        <param name="Ширина">500</param>
        <param name="Глубина">400</param>
        <param name="Высота">300</param>
      </offer>
      ...
    </offers>
  </shop>
</yml_catalog>
```

### Особенности
* Поддерживаются только валюта `RUB`, только `базовый тип цен`, только три параметра: `param[name="Ширина"]`, `param[name="Глубина"]`, `param[name="Высота"]`
* Читаемые поля `<offer>`: `offer[id]`, `<url>`, `<price>`[, `<oldprice>`], `<currenncyId>`, `<categoryId>`, `<name>`, `<description>`, `<param>`
* Сделаны скидки на товары
* Поддерживается как добавление у товаров полей/свойств/цен/скидок, так и их обновление, так и удаление несуществующих в yml-файле товаров
* Для определения товаров и скидок у них используются префиксы (`внешний код` у элементов и `название` у скидок)
* Можно удалить все созданные товары и скидки по их префиксам
* Сделана привязка категорий (`<categoryId>`) к имеющимся разделам каталога
* Картинки загружаются из локальной директории
* Торговые предложения схлопываются в один товар (предложения определяются по одинаковой ссылке (`<url>`) без GET-параметров) с минимальной ценой из всех предложений и объединенными параметрами (`<param>`)
* Есть более менее приличный вывод отладочной информации при импорте. Вызов через cron не сделан

### Привязка категорий
Привязка категорий yml-файла к разделам инфоблока реализована файлом, где категория yml-файла, которая привязывается идет до знака `=` (настраивается), а раздел, к которому привязываем, идет после знака `=`  
Путь категории/раздела состоит из наименования категории/раздела и присоединенных категорий/разделов предков, разбитых строкой ` → ` (настраивается)  
Есть привязка «без раздела», для этого надо написать: `Категория 1 = ` (обозначение товаров yml-файла `Категории 1`, как товаров без раздела инфоблока), ` = Раздел 1` (обозначение товаров yml-файла без категории, как товары привязанные к `Разделу 1` инфоблока), ` = ` (обозначение товаров yml-файла без категории, как товары без раздела инфоблока)  
Заглушку файла привязки категорий с именами категорий yml-файла и разделами инфоблока можно сгененировать  
  
```
Категория 1 = Раздел каталога 1
Категория 1 → Подкатегория 1 = Раздел каталога 1 → Подраздел 1
```

