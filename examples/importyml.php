<?php
define('STOP_STATISTICS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_SECURITY_SHOW_MESSAGE', true);
define('PUBLIC_AJAX_MODE', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

/**
 * Настройка autoload с vendor'ами
 * @see https://thisis-blog.ru/composer-dlya-avtozagruzki-klassov-bitriks/
 */

use Frizus\ImportYml\Main;

if (!$GLOBALS['USER']->IsAdmin()) {
    LocalRedirect(SITE_DIR, true);
    return;
}

@set_time_limit(0);
@ignore_user_abort(true);

$importYml = new Main([
    'iblockCode' => 'test', // код инфоблока (или ID инфоблока), в который идет импорт (должен быть торговым каталогом)
    'ymlFilePath' => __DIR__ . '/ymlfile.xml', // абсолютный путь до импортируемого файла
    'mappingFilePath' => __DIR__ . '/section_mapping.txt', // абсолютный путь до файла привязки категорий
    'picturesFilePath' => __DIR__ . '/img/[id].jpg', // локальный абсолютный путь до импортируемых картинок вида <путь>/[id картинки].jpg
    'elementPrefix' => 'Бренд X-', // префикс внешнего кода импортируемых элементов инфоблока
    'discountPrefix' => 'Бренд X скидка: ', // префикс названия скидки импортируемых скидок на товары
    'translitPrefix' => 'brand-x-id-', // префикс символьного кода импортируемых элементов инфоблока
    'mode' => $_GET['mode'], // режим работы: import, debug (по умолчанию)
    'softDelete' => $_GET['disable_soft_delete'] != 1, // если ложь, то при импорте удаляются несуществующие в yml-файле товары из инфоблока
    'exceptionOnDebug' => $_GET['exception_on_debug'] == 1, // выводить исключение в режиме работы debug (в режиме import любая ошибка прерывает работу скрипта)
    'action' => $_GET['action'], // доступные действия: import-items (по умолчанию), generate-mapping-file, delete-products-and-discounts
]);

$importYml->run();